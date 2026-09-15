<?php

declare(strict_types=1);

namespace App\Telephony\Flows;

/**
 * One supervisor monitoring a live call (SM slices 2-3). Deliberately NOT an
 * AgentLeg: a monitor is not a participant. Nobody on the call can hear them, the
 * conversation's own membership never changes, and the call's state does not move —
 * which is why adding a monitor needs no new CallFlowState and never touches the
 * battle-tested teardown branches.
 *
 * Three legs' worth of bookkeeping, held together because they are released together:
 *
 *   legId           the supervisor's own line, rung like any other phone
 *   tapLegId        the silent tap on the agent's line, created once the supervisor
 *                   picks up (nothing to feed before then)
 *   conversationId  the small mixer holding just those two, separate from the call's
 *                   own — so ending a monitoring session cannot touch the call
 *
 * `mode` is the whole of slice 3. 'listen' is silent both ways; 'whisper' pushes the
 * supervisor's voice into the AGENT's ear only. It is fixed when the session starts,
 * because a tap's whisper direction is set when the tap is created and cannot be read
 * back or changed afterwards — changing mode means a new session, which is why the two
 * buttons on the board are two sessions rather than one with a switch.
 *
 * `agentUserId` is stored rather than the agent's leg id because the two are not the
 * same thing over time: a transfer completing while the supervisor's phone rings
 * replaces the agent leg entirely. Re-reading the leg at pick-up either finds whoever
 * is serving that person's call now, or finds nobody and gives up cleanly.
 */
final class MonitorLeg
{
    public function __construct(
        public readonly string $legId,
        /** The supervisor doing the listening. */
        public readonly int $userId,
        /** The agent being monitored — resolved to a leg at pick-up, not before. */
        public readonly int $agentUserId,
        /** 'listen' (silent) or 'whisper' (the agent hears the supervisor, the customer does not). */
        public readonly string $mode = 'listen',
        /** The tap on the agent's line; null until the supervisor answers. */
        public ?string $tapLegId = null,
        /** The tap + supervisor mixer; null until the supervisor answers. */
        public ?string $conversationId = null,
    ) {}
}
