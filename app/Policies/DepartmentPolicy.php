<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Departments (inbound-audio slice 7, D2). Head office owns them: creates, renames,
 * switches off, deletes — because menu keys point at departments and menus are head
 * office only (AU-19).
 *
 * Team leaders see them and may UPDATE, but the form lets them change only who is in
 * one (DepartmentForm). Membership is daily staffing — a sick day at 9am — the same
 * "create and update, no delete" footing team leaders already have on operational data,
 * minus create, because a new department is only reachable through a menu key.
 *
 * super_admin still passes through Shield's Gate::before bypass regardless.
 */
class DepartmentPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->canManageMembers($user);
    }

    public function view(User $user): bool
    {
        return $this->canManageMembers($user);
    }

    public function create(User $user): bool
    {
        return $user->operatesGlobally();
    }

    public function update(User $user): bool
    {
        return $this->canManageMembers($user);
    }

    public function delete(User $user): bool
    {
        return $user->operatesGlobally();
    }

    /**
     * Asked by the table's bulk actions even when none is shown, and the panel runs
     * strictAuthorization() — a missing method is a 500 on the whole list page (S170).
     */
    public function deleteAny(User $user): bool
    {
        return $user->operatesGlobally();
    }

    private function canManageMembers(User $user): bool
    {
        return $user->operatesGlobally() || $user->hasRole(RoleName::TeamLeader->value);
    }
}
