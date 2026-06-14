<?php

declare(strict_types=1);

namespace App\Telephony\Flows;

/**
 * Where the one inbound-to-agent call sits in its lifecycle (B4 CP2a). The flow
 * drives exactly one call at a time in v1 (D5 — concurrency is B2), so a single
 * state value is all the machine needs.
 */
enum InboundFlowState
{
    /** No call in flight; ready to take a fresh caller. */
    case Idle;

    /** Caller answered; the agent's phone is ringing (awaiting pickup or timeout). */
    case RingingAgent;

    /** Caller and agent are joined and talking; both sides are being recorded. */
    case InCall;
}
