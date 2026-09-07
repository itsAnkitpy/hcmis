<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Audit\Audit;
use App\Enums\RoleName;
use App\Models\Tenant;
use App\Models\User;
use App\Telephony\AgentPhoneWriter;
use App\Tenancy\TenantContext;
use Filament\Actions\Action;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Mirror of UsersRelationManager (B.5.2) but on the User edit page — lists
 * which tenants this user belongs to and what role they hold on each. All
 * role writes happen inside TenantContext::run($tenant->id, …).
 */
class TenantsRelationManager extends RelationManager
{
    protected static string $relationship = 'tenants';

    protected static ?string $title = 'Clients';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('slug')->searchable()->sortable(),
                TextColumn::make('current_role')
                    ->label('Role on this client')
                    ->badge()
                    ->state(fn (Tenant $record): string => self::currentRoleFor($this->getOwnerRecord(), $record))
                    ->formatStateUsing(fn (string $state): string => $state === '—' ? '—' : Str::headline($state))
                    ->color(fn (string $state): string => $state === '—' ? 'gray' : 'primary'),
            ])
            ->defaultSort('name')
            ->headerActions([
                AttachAction::make()
                    ->label('Attach to client')
                    ->icon(Heroicon::OutlinedLink)
                    ->preloadRecordSelect()
                    ->recordSelectSearchColumns(['name', 'slug'])
                    ->schema(fn (AttachAction $action) => [
                        $action->getRecordSelect(),
                        Select::make('role_name')
                            ->label('Role on this client')
                            ->options(self::perClientRoleOptions())
                            ->default(RoleName::Agent->value)
                            ->required(),
                    ])
                    ->after(function (array $data): void {
                        /** @var User $user */
                        $user = $this->getOwnerRecord();
                        $tenantId = (int) $data['recordId'];

                        TenantContext::run($tenantId, function () use ($user, $data): void {
                            self::replaceRoleInCurrentTeam($user, $data['role_name']);
                        });
                    }),
            ])
            ->recordActions([
                Action::make('change_role')
                    ->label('Change role')
                    ->icon(Heroicon::OutlinedArrowsRightLeft)
                    ->schema(fn (Tenant $record) => [
                        Select::make('role_name')
                            ->label('Role on this client')
                            ->options(self::perClientRoleOptions())
                            ->default(self::currentRoleFor($this->getOwnerRecord(), $record))
                            ->required(),
                    ])
                    ->action(function (Tenant $record, array $data): void {
                        /** @var User $user */
                        $user = $this->getOwnerRecord();

                        TenantContext::run((int) $record->getKey(), function () use ($user, $data): void {
                            self::replaceRoleInCurrentTeam($user, $data['role_name']);
                        });
                    }),

                DetachAction::make()
                    ->label('Remove from client')
                    ->requiresConfirmation()
                    ->modalDescription('Removes this user from the client and clears their role on it. The user account stays.')
                    ->before(function (Tenant $record): void {
                        /** @var User $user */
                        $user = $this->getOwnerRecord();

                        TenantContext::run((int) $record->getKey(), function () use ($user, $record): void {
                            self::clearRolesInCurrentTeam($user);
                            Audit::roleRemoved($user, (int) $record->getKey());
                        });
                    }),
            ])
            ->toolbarActions([]);
    }

    private static function currentRoleFor(User $user, Tenant $tenant): string
    {
        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $user->getKey())
            ->where('model_has_roles.model_type', User::class)
            ->where('model_has_roles.team_id', $tenant->getKey())
            ->value('roles.name') ?? '—';
    }

    private static function replaceRoleInCurrentTeam(User $user, string $roleName): void
    {
        self::clearRolesInCurrentTeam($user);
        $user->assignRole($roleName);
        Audit::roleGranted($user, $roleName, TenantContext::id());

        self::givePhoneIfNowAnAgent($user, $roleName);
    }

    /**
     * Becoming an agent gets you a working phone, with nobody touching the switch
     * (SEC-1, PP-8). Both role-write paths — Attach to client and Change role — come
     * through here, so this is the one place it can happen.
     *
     * 🔴 The reverse never happens on its own (PP-9). Losing the agent role, or being
     * removed from the client entirely, leaves the phone alone: a role correction is
     * not a departure, and churning someone's extension would scramble the call
     * history that is read back against it. Retiring is a deliberate action (PP-19).
     *
     * The "already has one" check reads the owner record straight. Livewire re-fetches
     * it from the database on every request, so it cannot be stale — measured, not
     * assumed, by the test that allocates an extension behind an open page. That test
     * is what would catch it if the framework ever stopped doing so, because a second
     * number here would be genuinely free and the unique index would not fire.
     */
    private static function givePhoneIfNowAnAgent(User $user, string $roleName): void
    {
        if ($roleName !== RoleName::Agent->value) {
            return;
        }

        if ($user->sip_extension !== null) {
            return;
        }

        try {
            app(AgentPhoneWriter::class)->provisionFor($user);
        } catch (QueryException $e) {
            // 23505 is Postgres for "that number is already taken" — two admins
            // allocating in the same moment, which PP-1's unique index is there to
            // catch loudly rather than let two browsers share one phone. Anything
            // else is a real fault and must stay loud on its own terms.
            if ((string) $e->getCode() !== '23505') {
                throw $e;
            }

            report($e);

            Notification::make()
                ->warning()
                ->title('Role saved, but the phone was not created')
                ->body('Someone else was given a number at the same moment. Open Change role, pick Agent again, and they will get one.')
                ->persistent()
                ->send();
        }
    }

    private static function clearRolesInCurrentTeam(User $user): void
    {
        $teamId = TenantContext::id();

        if ($teamId === null) {
            return;
        }

        DB::table('model_has_roles')
            ->where('model_id', $user->getKey())
            ->where('model_type', User::class)
            ->where('team_id', $teamId)
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return array<string, string>
     */
    private static function perClientRoleOptions(): array
    {
        return collect(RoleName::perClient())
            ->mapWithKeys(fn (RoleName $r): array => [$r->value => Str::headline($r->value)])
            ->all();
    }
}
