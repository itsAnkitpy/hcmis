<?php

namespace App\Enums;

/**
 * Why an inbound call with no agent on it ended the way it did, beyond what `outcome`
 * says (inbound-audio.md slice 1, widened in slice 6).
 *
 * A separate value rather than new outcomes on purpose: outcome feeds every report and
 * the dialer's legal 3% figure, and this must change none of them. NULL is every missed
 * call without a special reason — the screen falls back to the outcome's own words.
 *
 * 🔴 NOT EVERY REASON BELONGS ON MISSED CALLS, and that is new in slice 6. A caller who
 * pressed "hear a message" or "take me off your list" got exactly what they rang for
 * (AU-26), so they need a call record for reporting and must never reach an agent's
 * callback list. The screen therefore asks `showsOnMissedCalls()` rather than reading
 * the outcome alone — so a reason added later cannot quietly leak onto that list.
 */
enum MissedReason: string
{
    /** Rang while the client was closed (AU-3). */
    case ClosedHours = 'closed_hours';

    /** Hung up while the menu was still asking (AU-26). Belongs on the list. */
    case HungUpInMenu = 'hung_up_in_menu';

    /** Chose a key that answered them and ended the call (AU-26). Kept off the list. */
    case ServedByMenu = 'served_by_menu';

    public function label(): string
    {
        return match ($this) {
            self::ClosedHours => 'Called while closed',
            self::HungUpInMenu => 'Hung up in the menu',
            self::ServedByMenu => 'Served by the menu',
        };
    }

    /**
     * Should a call filed under this reason appear on the Missed Calls list?
     *
     * The list is an agent's callback queue, so the question it answers is "does this
     * person still need ringing back?" — not "did an agent speak to them?".
     */
    public function showsOnMissedCalls(): bool
    {
        return $this !== self::ServedByMenu;
    }

    /**
     * The reasons that keep a call OFF the list, for the screen's own query.
     *
     * @return array<int, string>
     */
    public static function hiddenFromMissedCalls(): array
    {
        return array_values(array_map(
            static fn (self $reason): string => $reason->value,
            array_filter(self::cases(), static fn (self $reason): bool => ! $reason->showsOnMissedCalls()),
        ));
    }
}
