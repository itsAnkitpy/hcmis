<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\Call;
use App\Models\User;

/**
 * Call Review is READ-ONLY (CR-1) and auditor-only (CR-3). Read gate: global HC
 * staff (Super Admin / HC Admin / Ops Manager) + the per-client call auditors
 * Team Leader + QC. Trainer / Client User get none.
 *
 * The one row-based exception (My Day MD-1): an agent may `view` — and therefore
 * stream — a call they personally handled, so their own-day page can play their
 * own recordings (every listen audited, HD-3). `viewAny` stays auditor-only, so
 * the Call Review screen itself never opens to agents, and `download` is a
 * separate auditor-only ability (MD-4: play-only for agents).
 *
 * A call record is an immutable historical fact (the CDR), so every write and
 * delete is denied here; deletion belongs to the separate retention / erasure
 * module (FR-QC02 / FR-QC07). Row scoping (which client's calls) is the tenant
 * wall's job (RLS), enforced before a policy is reached — so apart from MD-1's
 * own-row check these are role-based, not row-based (the OperationalDataPolicy
 * convention).
 */
class CallPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canRead($user);
    }

    public function view(User $user, Call $call): bool
    {
        return $this->canRead($user) || $this->isOwnCall($user, $call);
    }

    /** MD-4: a file leaving the system is auditor-only; agents get in-page play. */
    public function download(User $user, Call $call): bool
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

    /** MD-1: the agent on the call, and still holding the Agent role. */
    protected function isOwnCall(User $user, Call $call): bool
    {
        return $call->agent_id === $user->id
            && $user->hasRole(RoleName::Agent->value);
    }
}
