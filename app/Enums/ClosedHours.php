<?php

namespace App\Enums;

/**
 * What a client's callers get when the client is closed (inbound-audio.md slices 1 and 4).
 *
 * Off is the default and means open 24 hours, today's behaviour (AU-1): most saved hours
 * are the onboarding form's untouched defaults.
 */
enum ClosedHours: string
{
    case Off = 'off';

    /** Closed: the call is not picked up. It ends with the busy reason (AU-2). */
    case NoPickup = 'no_pickup';

    /**
     * Closed: the call IS picked up, the client's own closed message plays, and the call
     * ends when it finishes (slice 4). The caller still lands on Missed Calls marked
     * "called while closed" (AU-3) — the message does not count as being helped.
     *
     * 🔴 This choice cannot be saved without an uploaded message, or a caller would be
     * answered into silence and then hung up on, which is worse than not picking up.
     */
    case Message = 'message';

    public function label(): string
    {
        return match ($this) {
            self::Off => 'Off — always open',
            self::NoPickup => 'Closed means no pick-up',
            self::Message => 'Closed means a message',
        };
    }
}
