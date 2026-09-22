<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Menus are HEAD OFFICE ONLY (inbound-audio AU-19), and this is the one place in the
 * phone-number story where that differs from everything around it.
 *
 * A team leader may add a number and point it at a campaign (ND-5, PhoneNumberPolicy),
 * because a wrong campaign mislabels a report. A wrong menu TRAPS EVERY CALLER ON THAT
 * NUMBER — a bad key, a missing greeting or a loop leaves them with no way to a person.
 * Aircall draws the same line: admins only. So this policy is Tenant's shape, not the
 * shared operational-data map.
 *
 * 🔴 HEAD OFFICE IS NEVER PINNED TO ONE CLIENT (SetCurrentTenant): a global user always
 * browses with no client selected and the cross-client posture on, which is why every
 * table here carries a Client column. So the menus screen shows every client's menus and
 * the form asks which client a new one is for — it cannot read that off the context, the
 * way a team leader's screens do.
 *
 * super_admin still passes through Shield's Gate::before bypass regardless.
 */
class MenuPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->operatesGlobally();
    }

    public function view(User $user): bool
    {
        return $user->operatesGlobally();
    }

    public function create(User $user): bool
    {
        return $user->operatesGlobally();
    }

    public function update(User $user): bool
    {
        return $user->operatesGlobally();
    }

    public function delete(User $user): bool
    {
        return $user->operatesGlobally();
    }
}
