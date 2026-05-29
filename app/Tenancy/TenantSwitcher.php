<?php

namespace App\Tenancy;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The validated "switch current client" action behind the (M4) topbar switcher.
 * Switching only records the choice in the session — and only after the
 * membership check. The SetCurrentTenant middleware re-validates on every
 * request, so a stale or tampered session value can never grant access.
 */
class TenantSwitcher
{
    /**
     * The clients this user may switch into.
     *
     * @return Collection<int, Tenant>
     */
    public static function allowedTenants(User $user): Collection
    {
        return $user->tenants()->orderBy('tenants.name')->get();
    }

    /**
     * Record the chosen client if the user may access it. Returns false
     * (default-deny) without touching the session otherwise.
     */
    public static function switchTo(User $user, Tenant|int $tenant): bool
    {
        $tenantId = $tenant instanceof Tenant ? (int) $tenant->getKey() : $tenant;

        if (! $user->mayAccessTenant($tenantId)) {
            return false;
        }

        session()->put('current_tenant_id', $tenantId);

        return true;
    }
}
