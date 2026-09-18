<?php

namespace App\Enums;

/**
 * What a client's callers get when the client is closed (inbound-audio.md slice 1).
 *
 * Off is the default and means open 24 hours, today's behaviour (AU-1): most saved hours
 * are the onboarding form's untouched defaults. "Closed means a message" joins in slice
 * 4, as a third value — which is why this is a named value and not a yes/no switch.
 */
enum ClosedHours: string
{
    case Off = 'off';

    /** Closed: the call is not picked up. It ends with the busy reason (AU-2). */
    case NoPickup = 'no_pickup';

    public function label(): string
    {
        return match ($this) {
            self::Off => 'Off — always open',
            self::NoPickup => 'Closed means no pick-up',
        };
    }
}
