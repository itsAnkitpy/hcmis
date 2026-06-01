<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Enums\RoleName;
use App\Models\User;

/**
 * The D-M4-5 access map, shared by the four operational-data resources
 * (Campaign, Lead, Disposition, Script) because the rules are identical for
 * every one. Hand-written + role-based — this IS the "granular and role-based
 * RBAC" FR-U02 asks for; Filament Shield's permission UI is not the decider.
 *
 *   super_admin / hc_admin / ops_manager  → full (read + write + delete)
 *   team_leader                           → read + create + update, NO delete
 *   qc / trainer                          → read-only
 *   agent / client_user                   → no access (their surface is Phase 2)
 *
 * Per-client roles (team_leader / qc / trainer) resolve against the request's
 * current tenant via TenantTeamResolver, so a team_leader of client A is only a
 * team_leader while operating in A — the rules apply uniformly to every tenant's
 * copy of the role with no per-tenant drift.
 *
 * super_admin additionally short-circuits every check via Shield's Gate::before.
 *
 * The model argument Filament passes to view/update/delete is intentionally not
 * declared: these decisions are role-based, not row-based (row scoping is the
 * tenant wall's job, already enforced before a policy is reached).
 */
trait OperationalDataPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canRead($user);
    }

    public function view(User $user): bool
    {
        return $this->canRead($user);
    }

    public function create(User $user): bool
    {
        return $this->canWrite($user);
    }

    public function update(User $user): bool
    {
        return $this->canWrite($user);
    }

    public function delete(User $user): bool
    {
        return $this->canDelete($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->canDelete($user);
    }

    protected function canRead(User $user): bool
    {
        return $user->operatesGlobally()
            || $user->hasAnyRole([
                RoleName::TeamLeader->value,
                RoleName::Qc->value,
                RoleName::Trainer->value,
            ]);
    }

    protected function canWrite(User $user): bool
    {
        return $user->operatesGlobally()
            || $user->hasRole(RoleName::TeamLeader->value);
    }

    protected function canDelete(User $user): bool
    {
        return $user->operatesGlobally();
    }
}
