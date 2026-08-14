<?php

namespace App\Enums;

/**
 * Which side ended the call (call-export.md CE-11). Their sheet calls it "Hangup
 * reason" / "Term reason"; a supervisor reading a very short call wants to know who
 * put the phone down.
 *
 * We already branched on this and threw it away — the teardown code has always had one
 * path for "the caller's leg went" and another for "a connected agent's leg went".
 *
 * NULL is a real and honest value, not a gap to be filled: a call torn down by an
 * error, or one that never connected to anybody, has no side that hung up. CE-4's rule
 * applies — a blank beats an invented value.
 */
enum CallEndedBy: string
{
    case Customer = 'customer';
    case Agent = 'agent';

    /**
     * Nobody hung up: we stopped waiting on the caller's behalf and ended the call
     * ourselves, which is what happens when the maximum hold time runs out (QD-6).
     * Distinct from a blank, which means we do not know.
     */
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Customer',
            self::Agent => 'Agent',
            self::System => 'System',
        };
    }
}
