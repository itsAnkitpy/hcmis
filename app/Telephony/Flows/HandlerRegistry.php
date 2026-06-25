<?php

declare(strict_types=1);

namespace App\Telephony\Flows;

use App\Telephony\RecordingSession;

/**
 * The thin handle a per-call handler (CallToAgentFlow) is given at birth so it
 * can talk back to the switchboard (B2.1 / FD-2). A handler stays the authority
 * on its own legs: the moment it places one it registers the leg here, and at
 * teardown it releases all of them. It also hands its in-flight recording merge
 * here on the way out, because a recording can finish a moment AFTER the call
 * hangs up — and the now-short-lived handler must not take that pending merge to
 * the grave (FD-4). The Switchboard is the only implementation.
 */
interface HandlerRegistry
{
    /** Book "this phone leg belongs to that handler" so later events route to it. */
    public function registerLeg(string $legId, CallToAgentFlow $handler): void;

    /** Forget every leg that pointed at this handler — it is being torn down for good. */
    public function release(CallToAgentFlow $handler): void;

    /**
     * Hand an in-flight recording merge to the switchboard's shared notebook, so a
     * RecordingFinished that arrives after this handler is gone still merges (FD-4).
     */
    public function depositMerge(string $callId, RecordingSession $recording): void;
}
