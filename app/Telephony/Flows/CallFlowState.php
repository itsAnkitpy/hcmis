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
}
