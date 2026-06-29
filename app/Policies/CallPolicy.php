<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\User;

/**
 * Call Review is READ-ONLY (CR-1) and auditor-only (CR-3). Read gate: global HC
 * staff (Super Admin / HC Admin / Ops Manager) + the per-client call auditors
 * Team Leader + QC. Agent / Trainer / Client User get none in v1 — a client-facing
 * recording view is a clean additive seam later (the posture the audit log takes).
 *
 * A call record is an immutable historical fact (the CDR), so every write and
 * delete is denied here; deletion belongs to the separate retention / erasure
 * module (FR-QC02 / FR-QC07). Row scoping (which client's calls) is the tenant
 * wall's job (RLS), enforced before a policy is reached — so these checks are
 * role-based, not row-based (the OperationalDataPolicy convention).
 */
class CallPolicy
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
        return false;
    }

    public function update(User $user): bool
    {
        return false;
    }

    public function delete(User $user): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    protected function canRead(User $user): bool
    {
        return $user->operatesGlobally()
            || $user->hasAnyRole([
                RoleName::TeamLeader->value,
                RoleName::Qc->value,
            ]);
    }
}
