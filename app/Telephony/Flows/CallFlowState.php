<?php

declare(strict_types=1);

namespace App\Telephony\Flows;

/**
 * Where the one call-to-agent flow sits in its lifecycle (B4 CP2a, generalised
 * to outbound at B-outbound M2). The flow drives exactly one call at a time in
 * v1 (concurrency is B2), so a single state value is all the machine needs.
 *
 * Two entry paths share the same Idle and InCall ends; only the ringing stage
 * differs by direction:
 *   - inbound:  caller answered -> RingingAgent    (the agent's phone rings)
 *   - outbound: agent leg is up -> RingingCustomer (the customer's phone rings)
 *
 * B2.4a/B2.4b add one extra stage that hangs off InCall, not Idle: the live call is
 * still joined to its serving agent(s) while a SECOND agent (B) is being rung in. The
 * ring is identical for a cold transfer and a 3-way conference (the caller is never left
 * alone); an intent flag on the handler forks only what happens when B answers — drop A
 * (transfer) or keep A (conference). The machine leaves InCall for AddingAgent and
 * returns to InCall either way: B answered, or B didn't (the call is unchanged).
 */
enum CallFlowState
{
    /** No call in flight; ready to take a fresh caller or an agent-initiated dial. */
    case Idle;

    /** Inbound: caller answered; the agent's phone is ringing (awaiting pickup or timeout). */
    case RingingAgent;

    /** Outbound: agent leg is up; the customer's phone is ringing (awaiting pickup or timeout). */
    case RingingCustomer;

    /** The caller is joined to one or more connected agents and being recorded. */
    case InCall;

    /**
     * Adding a second agent to a live call (B2.4b CD-5; was B2.4a's Transferring): the
     * caller stays joined to the serving agent (A) while a free agent (B) is being rung.
     * B answering forks on the handler's intent — transfer (drop A) or conference (keep
     * A, the 3-way). B not answering (or the caller leaving) returns to InCall. The
     * caller is never alone — the never-strand rule, shared with the cold transfer.
     */
    case AddingAgent;

    /**
     * The caller is answered and holding with music on, waiting for a desk to free
     * up (B2.3b-i QD-2). Reached from BOTH ways a caller used to be hung up on:
     * nobody was free when they arrived, and an agent let their phone ring out.
     *
     * The waiting LINE needs no data structure of its own: the switchboard already
     * holds one handler per live call in arrival order, so "everyone waiting, oldest
     * first" is simply its Waiting handlers in order (QD-3's sweep). Leaves for
     * RingingAgent when the sweep pairs the caller with a freed agent, or ends when
     * the caller gives up / the client's maximum hold time runs out.
     */
    case Waiting;
}
