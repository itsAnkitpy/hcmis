<?php

declare(strict_types=1);

namespace App\Policies;

use App\Policies\Concerns\OperationalDataPolicy;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * The number -> client screen is the BPO team lead's, not admin-only (ND-5): they
 * already add numbers and assign them to campaigns in their current system. The
 * shared operational-data map is exactly that shape — team_leader reads, creates
 * and updates; ops_manager and above may also delete.
 */
class PhoneNumberPolicy
{
    use HandlesAuthorization;
    use OperationalDataPolicy;
}
