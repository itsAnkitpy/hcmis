<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\User;

/**
 * The audit log is READ-ONLY and operator/compliance-only (D-M7-4). Read gate:
 * Super Admin / HC Admin / Ops Manager (global staff) + QC (the compliance
 * reader). Team Leader / Trainer / Agent / Client User: none. Client users get
 * no access in Phase 1 — audit is an operator tool; the variant RLS already
 * makes a client transparency view a zero-rework add later.
 *
 * Every write and delete is denied here too — the log is append-only (D-M7-5),
 * reinforced by the DB-level REVOKE. This is deliberately NOT the shared
 * OperationalDataPolicy, which grants Team Leader create/update.
 */
class ActivityLogPolicy
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
        return $user->operatesGlobally() || $user->hasRole(RoleName::Qc->value);
    }
}
