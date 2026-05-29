<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Phase-1 coarse gate for the Tenants resource: HC global staff only
 * (super_admin / hc_admin / ops_manager). Every ability resolves to
 * User::operatesGlobally(), so client staff (agents, TLs, QC, client users)
 * can neither see the resource in the sidebar nor reach its pages by URL —
 * closing read access to every client's config and the suspend/archive actions.
 *
 * super_admin still passes through Shield's Gate::before bypass regardless. M4
 * replaces this all-or-nothing gate with granular, Shield-permission-based
 * policies (per-action, per-role).
 */
class TenantPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->operatesGlobally();
    }

    public function view(User $user, Tenant $tenant): bool
    {
        return $user->operatesGlobally();
    }

    public function create(User $user): bool
    {
        return $user->operatesGlobally();
    }

    public function update(User $user, Tenant $tenant): bool
    {
        return $user->operatesGlobally();
    }

    public function delete(User $user, Tenant $tenant): bool
    {
        return $user->operatesGlobally();
    }

    public function deleteAny(User $user): bool
    {
        return $user->operatesGlobally();
    }

    public function attach(User $user, Tenant $tenant): bool
    {
        return $user->operatesGlobally();
    }

    public function attachAny(User $user): bool
    {
        return $user->operatesGlobally();
    }

    public function detach(User $user, Tenant $tenant): bool
    {
        return $user->operatesGlobally();
    }

    public function detachAny(User $user): bool
    {
        return $user->operatesGlobally();
    }
}
