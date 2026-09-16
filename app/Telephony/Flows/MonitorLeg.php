<?php

declare(strict_types=1);

namespace App\Telephony\Flows;

/**
 * One supervisor monitoring a live call (SM slices 2-4). Deliberately NOT an
 * AgentLeg: a monitor is not a participant. Nobody on the call can hear them, the
 * conversation's own membership never changes, and the call's state does not move —
 * which is why adding a monitor needs no new CallFlowState and never touches the
 * battle-tested teardown branches.
 *
 * 🔴 BARGE BENDS THAT IN EXACTLY ONE PLACE AND NO OTHER (slice 4). A barging
 * supervisor IS audible, because their line joins the call's own conversation. They
 * still never join $agents, so they are still not a participant as far as every
 * report, every timing stamp and every teardown branch is concerned — which is the
 * whole of SM-5's promise that per-agent figures cannot move.
 *
 * Three legs' worth of bookkeeping, held together because they are released together:
 *
 *   legId           the supervisor's own line, rung like any other phone
 *   tapLegId        the silent tap on the agent's line, created once the supervisor
 *                   picks up (nothing to feed before then). Never set for barge —
 *                   barge does not tap anything, it joins.
 *   conversationId  the small mixer holding just those two, separate from the call's
 *                   own — so ending a monitoring session cannot touch the call.
 *                   🔴 Never set for barge either, and that is a safety property
 *                   rather than a tidiness one: teardown ends whatever conversation
 *                   this field names, and for a barge that would be the CALL's.
 *
 * `mode` carries slices 3 and 4. 'listen' is silent both ways; 'whisper' pushes the
 * supervisor's voice into the AGENT's ear only; 'barge' puts them in the conversation
 * where everybody hears them. It is fixed when the session starts, because a tap's
 * whisper direction is set when the tap is created and cannot be read back or changed
 * afterwards — changing mode means a new session, which is why the buttons on the board
 * are separate sessions rather than one with a switch.
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
        /** 'listen' (silent), 'whisper' (the agent hears the supervisor, the customer does not), or 'barge' (everybody hears them). */
        public readonly string $mode = 'listen',
        /** The tap on the agent's line; null until the supervisor answers. */
        public ?string $tapLegId = null,
        /** The tap + supervisor mixer; null until the supervisor answers. */
        public ?string $conversationId = null,
    ) {}
}
