<?php

declare(strict_types=1);

namespace App\Policies;

use App\Policies\Concerns\OperationalDataPolicy;
use Illuminate\Auth\Access\HandlesAuthorization;

class LeadPolicy
{
    use HandlesAuthorization;
    use OperationalDataPolicy;
}
