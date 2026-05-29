<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Enums\RoleName;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Filament\Actions\Action;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
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

                        TenantContext::run((int) $record->getKey(), function () use ($user): void {
                            self::clearRolesInCurrentTeam($user);
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
