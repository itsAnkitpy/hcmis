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
 * B2.4a adds one extra stage that hangs off InCall, not Idle: a cold transfer
 * briefly rings a SECOND agent (B) while the first (A) keeps talking, so the
 * machine leaves InCall for Transferring and returns to InCall either way — B
 * answered (now serving the caller) or B didn't (A still serving).
 */
enum CallFlowState
{
    /** No call in flight; ready to take a fresh caller or an agent-initiated dial. */
    case Idle;

    /** Inbound: caller answered; the agent's phone is ringing (awaiting pickup or timeout). */
    case RingingAgent;

    /** Outbound: agent leg is up; the customer's phone is ringing (awaiting pickup or timeout). */
    case RingingCustomer;

    /** Both legs are joined and talking; both sides are being recorded. */
    case InCall;

    /**
     * Cold transfer in progress (B2.4a TD-5): the caller is still joined to the
     * serving agent (A) while a free agent (B) is being rung. B answering promotes
     * B and drops A; B not answering (or the caller leaving) returns to InCall with
     * A unchanged. The caller is never alone — "supervised cold transfer".
     */
    case Transferring;
}
