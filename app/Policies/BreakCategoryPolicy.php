<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\OperationalDataPolicy;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * The D-M4-5 operational-data access map, minus delete: break categories are
 * deactivated, never deleted (BK-1), because status-history rows (BK-2) point
 * at a category id and must keep resolving forever.
 *
 * super_admin still bypasses via Shield's Gate::before — the UI offers no
 * delete action anywhere, and the BK-2 history FK will refuse the delete at
 * the database as the final guard.
 */
class BreakCategoryPolicy
{
    use HandlesAuthorization;
    use OperationalDataPolicy;

    public function delete(User $user): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
