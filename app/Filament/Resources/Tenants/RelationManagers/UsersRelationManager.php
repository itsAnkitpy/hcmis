<?php

namespace App\Filament\Resources\Tenants\RelationManagers;

use App\Audit\Audit;
use App\Enums\RoleName;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\Actions\SendUserInvite;
use App\Tenancy\TenantContext;
use Filament\Actions\Action;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Per-tenant agent management (M3 B.5.2).
 *
 * Lists the users attached to this tenant, with their per-tenant role pulled
 * from the spatie team-scoped role table. Add / Attach / Change role / Detach
 * all route through TenantContext::run($tenant->id, …) so role writes resolve
 * to this tenant's team via TenantTeamResolver — never team 0.
 *
 * New users land with email_verified_at = null and a random unusable password.
 * Checkpoint C wires the invite email + first-password flow on top — both
 * "Add new user" here and the wizard's agent step will hand off to the same
 * invite path.
 */
class UsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $title = 'Users (per-tenant)';

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
                TextColumn::make('email')->searchable()->sortable(),
                IconColumn::make('email_verified_at')
                    ->label('Verified')
                    ->boolean()
                    ->getStateUsing(fn (User $record): bool => $record->email_verified_at !== null),
                TextColumn::make('current_role')
                    ->label('Role on this client')
                    ->badge()
                    ->state(fn (User $record): string => self::currentRoleFor($record, $this->getOwnerRecord()))
                    ->formatStateUsing(fn (string $state): string => $state === '—' ? '—' : Str::headline($state))
                    ->color(fn (string $state): string => $state === '—' ? 'gray' : 'primary'),
            ])
            ->defaultSort('name')
            ->headerActions([
                Action::make('add_new')
                    ->label('Add new user')
                    ->icon(Heroicon::OutlinedUserPlus)
                    ->modalHeading('Add a new user to this client')
                    ->schema([
                        TextInput::make('name')->required()->maxLength(255),
                        TextInput::make('email')
                            ->required()
                            ->email()
                            ->maxLength(255)
                            ->unique(User::class, 'email'),
                        Select::make('role_name')
                            ->label('Role')
                            ->options(self::perClientRoleOptions())
                            ->default(RoleName::Agent->value)
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        /** @var Tenant $tenant */
                        $tenant = $this->getOwnerRecord();

                        $created = null;

                        TenantContext::run($tenant->getKey(), function () use ($data, $tenant, &$created): void {
                            $created = User::create([
                                'name' => $data['name'],
                                'email' => $data['email'],
                                'password' => Hash::make(Str::password(40)),
                            ]);

                            $tenant->users()->attach($created->getKey());
                            $created->assignRole($data['role_name']);
                        });

                        if ($created !== null && ! SendUserInvite::trySend($created, $tenant)) {
                            Notification::make()
                                ->warning()
                                ->title('Invite email could not be sent')
                                ->body("{$created->email} was added to this client. Use \"Resend invite\" to try again.")
                                ->send();
                        }
                    }),

                AttachAction::make()
                    ->label('Attach existing user')
                    ->icon(Heroicon::OutlinedLink)
                    ->preloadRecordSelect()
                    ->recordSelectSearchColumns(['name', 'email'])
                    ->schema(fn (AttachAction $action) => [
                        $action->getRecordSelect(),
                        Select::make('role_name')
                            ->label('Role on this client')
                            ->options(self::perClientRoleOptions())
                            ->default(RoleName::Agent->value)
                            ->required(),
                    ])
                    ->after(function (array $data): void {
                        /** @var Tenant $tenant */
                        $tenant = $this->getOwnerRecord();
                        $user = User::find($data['recordId']);

                        if ($user === null) {
                            return;
                        }

                        TenantContext::run($tenant->getKey(), function () use ($user, $data): void {
                            self::replaceRole($user, $data['role_name']);
                        });
                    }),
            ])
            ->recordActions([
                Action::make('resend_invite')
                    ->label('Resend invite')
                    ->icon(Heroicon::OutlinedEnvelope)
                    ->color('gray')
                    ->visible(fn (User $record): bool => $record->email_verified_at === null)
                    ->requiresConfirmation()
                    ->modalDescription('Sends a fresh signed invite link to this user. Any previous unused link stops working when it expires.')
                    ->action(function (User $record): void {
                        /** @var Tenant $tenant */
                        $tenant = $this->getOwnerRecord();

                        $notification = Notification::make();

                        if (SendUserInvite::trySend($record, $tenant)) {
                            $notification->success()
                                ->title('Invite resent')
                                ->body("A fresh invite email is on its way to {$record->email}.");
                        } else {
                            $notification->danger()
                                ->title('Invite could not be sent')
                                ->body('Sending failed — check the mail configuration and try again.');
                        }

                        $notification->send();
                    }),

                Action::make('change_role')
                    ->label('Change role')
                    ->icon(Heroicon::OutlinedArrowsRightLeft)
                    ->schema(fn (User $record) => [
                        Select::make('role_name')
                            ->label('Role on this client')
                            ->options(self::perClientRoleOptions())
                            ->default(self::currentRoleFor($record, $this->getOwnerRecord()))
                            ->required(),
                    ])
                    ->action(function (User $record, array $data): void {
                        /** @var Tenant $tenant */
                        $tenant = $this->getOwnerRecord();

                        TenantContext::run($tenant->getKey(), function () use ($record, $data): void {
                            self::replaceRole($record, $data['role_name']);
                        });
                    }),

                DetachAction::make()
                    ->label('Remove from client')
                    ->requiresConfirmation()
                    ->modalDescription('Removes this user from the client and clears their role on it. Their account stays — they just leave this tenant.')
                    ->before(function (User $record): void {
                        /** @var Tenant $tenant */
                        $tenant = $this->getOwnerRecord();

                        TenantContext::run($tenant->getKey(), function () use ($record, $tenant): void {
                            self::clearPerTenantRoles($record);
                            Audit::roleRemoved($record, $tenant->getKey());
                        });
                    }),
            ])
            ->toolbarActions([]);
    }

    /**
     * The user's role name for the given tenant, or '—' if none. Queried
     * directly against model_has_roles to avoid spatie's contextual team
     * scoping (which would re-filter by whatever TenantContext is set to at
     * render time — usually null/global for super-admins on this page).
     */
    private static function currentRoleFor(User $user, Tenant $tenant): string
    {
        $name = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $user->getKey())
            ->where('model_has_roles.model_type', User::class)
            ->where('model_has_roles.team_id', $tenant->getKey())
            ->value('roles.name');

        return $name ?? '—';
    }

    /**
     * Replace the user's per-tenant role assignment. Caller must wrap in
     * TenantContext::run($tenant->id, …) so $user->assignRole() resolves to
     * the correct team via TenantTeamResolver.
     */
    private static function replaceRole(User $user, string $roleName): void
    {
        self::clearPerTenantRoles($user);
        $user->assignRole($roleName);
        Audit::roleGranted($user, $roleName, TenantContext::id());
    }

    /**
     * Strip every per-client role assignment the user holds in the current
     * TenantContext's team. Direct delete on model_has_roles — bypasses
     * spatie's removeRole() to avoid double team scoping confusion, then
     * invalidates the permission cache so subsequent reads see the change.
     */
    private static function clearPerTenantRoles(User $user): void
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
     * Per-client role select options, humanised.
     *
     * @return array<string, string>
     */
    private static function perClientRoleOptions(): array
    {
        return collect(RoleName::perClient())
            ->mapWithKeys(fn (RoleName $r): array => [$r->value => Str::headline($r->value)])
            ->all();
    }
}
