<?php

namespace App\Enums;

/**
 * Why a caller is on Missed Calls, beyond what `outcome` says (inbound-audio.md slice 1).
 *
 * A separate value rather than new outcomes on purpose: outcome feeds every report and
 * the dialer's legal 3% figure, and this must change none of them. NULL is every missed
 * call without a special reason — the screen falls back to the outcome's own words.
 */
enum MissedReason: string
{
    /** Rang while the client was closed (AU-3). */
    case ClosedHours = 'closed_hours';

    public function label(): string
    {
        return match ($this) {
            self::ClosedHours => 'Called while closed',
        };
    }
}
