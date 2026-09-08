<?php

namespace App\Tenancy\Actions;

use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Strip every per-client role this user holds in the current TenantContext's
 * team (M3 §5.4).
 *
 * Called by AssignTenantRole before it writes the new role, and by the
 * "Remove from client" button on both the user's page and the client's page.
 * It lived as the same eight lines copied into both relation managers until
 * SEC-1 needed a third copy — one action is what stops the copies drifting.
 *
 * Direct delete on model_has_roles rather than spatie's removeRole(), which
 * would re-apply team scoping on top of the team we are already scoped to,
 * then invalidates the permission cache so later reads see the change.
 *
 * A no-op outside a tenant context: global roles are written at team null and
 * are not this action's business.
 */
class ClearTenantRoles
{
    public function __invoke(User $user): void
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
}
