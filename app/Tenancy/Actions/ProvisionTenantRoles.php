<?php

namespace App\Tenancy\Actions;

use App\Enums\RoleName;
use App\Models\Tenant;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Create the 5 per-client roles scoped to a tenant's team (M3 §5.4).
 *
 * Called from the M3 onboarding wizard and from TenantObserver::created, so
 * every code path that produces a tenant (wizard, future API, factory states,
 * a one-off command) ends up with the same role set. Idempotent —
 * Role::firstOrCreate is the unit of work.
 *
 * Roles are written with team_id = tenant->id explicitly, so this action does
 * not depend on TenantContext for correctness — but callers may still want to
 * be inside TenantContext::run($tenant) so subsequent assignments resolve to
 * the same team via TenantTeamResolver.
 */
class ProvisionTenantRoles
{
    public function __invoke(Tenant $tenant): void
    {
        foreach (RoleName::perClient() as $role) {
            Role::firstOrCreate(
                [
                    'name' => $role->value,
                    'guard_name' => 'web',
                    'team_id' => $tenant->getKey(),
                ],
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Convenience callable for use as an action target.
     */
    public static function run(Tenant $tenant): void
    {
        (new self)($tenant);
    }
}
