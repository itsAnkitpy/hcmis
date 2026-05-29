<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Contracts\PermissionsTeamResolver;

/**
 * Ties spatie/laravel-permission's "team" to our M1 tenant context — team =
 * tenant (FR-U03). Role checks then auto-scope to whichever client the request
 * is operating in, with no manual setPermissionsTeamId() calls.
 *
 * When no tenant is set (HC's own staff operating cross-tenant), we fall back
 * to the reserved GLOBAL team. spatie's model_has_roles.team_id is part of the
 * primary key and therefore NOT NULL, so a literal-null assignment is
 * impossible; tenant ids start at 1, so id 0 is a safe sentinel for "global /
 * HC" roles such as super_admin.
 */
class TenantTeamResolver implements PermissionsTeamResolver
{
    /** Reserved team id for global / HC-wide role assignments. */
    public const GLOBAL_TEAM_ID = 0;

    protected int|string|null $teamId = null;

    public function getPermissionsTeamId(): int|string|null
    {
        return TenantContext::id() ?? $this->teamId ?? self::GLOBAL_TEAM_ID;
    }

    public function setPermissionsTeamId(int|string|Model|null $id): void
    {
        if ($id instanceof Model) {
            $id = $id->getKey();
        }

        $this->teamId = $id;
    }
}
