<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesSeeder extends Seeder
{
    /**
     * Global HC roles (reserved global team id 0) — held by HC's own staff who
     * operate ACROSS clients and carry no tenant membership, so their role
     * checks resolve against the global team.
     *
     * @deprecated Use RoleName::globalValues() — kept as a thin alias so
     * existing references keep working until they're migrated to the enum.
     */
    public const GLOBAL_ROLES = ['super_admin', 'hc_admin', 'ops_manager'];

    /**
     * Per-client roles — provisioned per tenant at onboarding (M3), scoped to
     * that tenant's team. Listed here as the shared vocabulary; NOT created
     * globally. See PRD/phase-1/m2-identity.md §5.
     *
     * @deprecated Use RoleName::perClientValues().
     */
    public const PER_CLIENT_ROLES = ['team_leader', 'qc', 'trainer', 'agent', 'client_user'];

    public function run(): void
    {
        // No tenant context here, so the resolver yields the reserved GLOBAL
        // team (id 0): these roles and their assignments are HC-wide.
        foreach (RoleName::globals() as $role) {
            Role::findOrCreate($role->value, 'web');
        }

        User::where('email', 'admin@hcmis.test')->first()?->assignRole(RoleName::SuperAdmin->value);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
