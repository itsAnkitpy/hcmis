<?php

declare(strict_types=1);

namespace App\Telephony\Flows;

/**
 * Why a second agent (B) is being rung into a live call (B2.4b CD-5). The ring itself
 * is byte-identical for both — caller stays with agent A, a reserved B rings, the proven
 * no-answer release guards B — so a single "ringing an added agent" state carries this
 * flag and the flag forks ONLY the on-answer action:
 *   - Transfer:   add B, then drop + hang up A (B2.4a cold transfer — the caller ends up with B).
 *   - Conference: add B and KEEP A (the 3-way — caller + A + B all talking, CD-4).
 *
 * Supervisor barge joins here later as a third intent (CD-5 names it); not built yet.
 */
enum AddedAgentIntent
{
    case Transfer;

    case Conference;
}
