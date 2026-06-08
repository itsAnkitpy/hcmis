<?php

declare(strict_types=1);

namespace App\Policies;

use App\Policies\Concerns\OperationalDataPolicy;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * DNC entries follow the same D-M4-5 role map as the other operational data
 * (Campaign / Lead / Disposition / Script): global staff full, team_leader
 * read+create+update (no delete), qc/trainer read-only, agent/client none.
 *
 * Delete (= making a number callable again — compliance-sensitive) is therefore
 * restricted to global staff by design. QC stays read-only for now (D-M6-6); a
 * QC-write variant can be added when the compliance workflow is built later.
 */
class DncEntryPolicy
{
    use HandlesAuthorization;
    use OperationalDataPolicy;
}
