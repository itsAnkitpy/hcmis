<?php

namespace App\Enums;

/**
 * An agent's live availability on the who's-free board (B2.2 PD-2). Five states:
 * Ready (free to take a call), OnCall (ringing/connected — being-rung folds in
 * here, PD-2), WrappingUp (after-call work), OnBreak (the one manual "away"
 * toggle), Offline (tab closed / logged out / heartbeat gone stale, PD-4).
 *
 * The screen is the single writer (PD-3): four states it sets automatically as
 * calls happen, OnBreak is the one button. The watcher only reads (and not until
 * 2.2b — in 2.2a "don't ring a busy agent" is enforced at the screen).
 */
enum PresenceStatus: string
{
    case Ready = 'ready';
    case OnCall = 'on_call';
    case WrappingUp = 'wrapping_up';
    case OnBreak = 'on_break';
    case Offline = 'offline';

    public function label(): string
    {
        return match ($this) {
            self::Ready => 'Ready',
            self::OnCall => 'On a call',
            self::WrappingUp => 'Wrapping up',
            self::OnBreak => 'On break',
            self::Offline => 'Offline',
        };
    }

    /**
     * Whether an agent in this state can be rung — the routing question the
     * watcher will ask in 2.2b. Only Ready is free; everything else is busy or
     * away. (Defined now so 2.2b reads it rather than re-deriving the rule.)
     */
    public function isAvailable(): bool
    {
        return $this === self::Ready;
    }
}
