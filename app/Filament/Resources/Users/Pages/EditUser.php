<?php

namespace App\Filament\Resources\Users\Pages;

use App\Enums\RoleName;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Tenancy\TenantContext;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Edit page for the global Users resource. Backs the form's synthetic fields
 * (email_verified toggle, global_roles checkbox list) — they aren't real
 * columns/relations, so we translate them on fill and on save.
 */
class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var User $user */
        $user = $this->record;

        $data['email_verified'] = $user->email_verified_at !== null;
        $data['global_roles'] = self::currentGlobalRoleValues($user);

        return $data;
    }

    /**
     * Refuse to strip super_admin from the last super admin — that would lock
     * everyone out of the global resources (no recovery without a seeder/tinker).
     * Runs before the record is written (EditRecord fires beforeSave inside
     * getState(), ahead of handleRecordUpdate + afterSave), so halting here
     * leaves nothing half-saved.
     */
    protected function beforeSave(): void
    {
        $selected = array_values(array_intersect(
            collect($this->form->getRawState()['global_roles'] ?? [])
                ->filter(fn ($v): bool => $v !== null && $v !== '')
                ->all(),
            RoleName::globalValues(),
        ));

        $superAdmin = RoleName::SuperAdmin->value;

        $removingSuperAdmin = ! in_array($superAdmin, $selected, strict: true);
        $currentlySuperAdmin = in_array($superAdmin, self::currentGlobalRoleValues($this->record), strict: true);

        if ($removingSuperAdmin && $currentlySuperAdmin && self::superAdminCount() <= 1) {
            Notification::make()
                ->danger()
                ->title('Cannot remove the last super admin')
                ->body('At least one super admin must remain. Grant super_admin to another user first, then retry.')
                ->send();

            $this->halt();
        }
    }

    /**
     * Both synthetic fields (email_verified toggle, global_roles checkbox
     * list) are dehydrated(false), so they never reach $data. Handle them
     * here from the raw form state — same hook as global roles for clarity.
     */
    protected function afterSave(): void
    {
        $raw = $this->form->getRawState();

        // --- Email verified toggle → email_verified_at column
        $shouldBeVerified = (bool) ($raw['email_verified'] ?? false);
        $currentlyVerified = $this->record->email_verified_at !== null;
        if ($shouldBeVerified !== $currentlyVerified) {
            $this->record->forceFill([
                'email_verified_at' => $shouldBeVerified ? now() : null,
            ])->save();
        }

        // --- Global roles sync → model_has_roles with team_id = 0
        $selected = collect($raw['global_roles'] ?? [])
            ->filter(fn ($v) => $v !== null && $v !== '')
            ->values()
            ->all();
        $selected = array_values(array_intersect($selected, RoleName::globalValues()));

        TenantContext::forget();
        self::syncGlobalRoles($this->record, $selected);
    }

    /**
     * How many users hold super_admin at the global team (id 0). Used to block
     * removing the role from the last one standing.
     */
    private static function superAdminCount(): int
    {
        return (int) DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', User::class)
            ->where('model_has_roles.team_id', 0)
            ->where('roles.name', RoleName::SuperAdmin->value)
            ->distinct()
            ->count('model_has_roles.model_id');
    }

    /**
     * @return array<int, string>
     */
    private static function currentGlobalRoleValues(User $user): array
    {
        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $user->getKey())
            ->where('model_has_roles.model_type', User::class)
            ->where('model_has_roles.team_id', 0)
            ->pluck('roles.name')
            ->all();
    }

    /**
     * Sync the user's global-role assignments (team_id = 0) to exactly the
     * given set. Direct table mutation + cache invalidate — same pattern as
     * UsersRelationManager, avoiding spatie's contextual team scoping.
     *
     * @param  array<int, string>  $roleNames
     */
    private static function syncGlobalRoles(User $user, array $roleNames): void
    {
        DB::table('model_has_roles')
            ->where('model_id', $user->getKey())
            ->where('model_type', User::class)
            ->where('team_id', 0)
            ->delete();

        foreach ($roleNames as $name) {
            $roleId = DB::table('roles')
                ->where('name', $name)
                ->where('guard_name', 'web')
                ->where('team_id', 0)
                ->value('id');

            if ($roleId === null) {
                continue;
            }

            DB::table('model_has_roles')->insert([
                'role_id' => $roleId,
                'model_id' => $user->getKey(),
                'model_type' => User::class,
                'team_id' => 0,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
