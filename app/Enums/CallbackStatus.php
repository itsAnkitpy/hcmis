<?php

namespace App\Enums;

/**
 * A callback's lifecycle state (M4 D1). v1 is sticky-only and has exactly two
 * states: a scheduled callback is Pending until the agent dials it, which marks
 * it Done. The claimed / missed states arrive with the pooled (any-agent) queue
 * in B2 — on this same table, an additive change.
 */
enum CallbackStatus: string
{
    case Pending = 'pending';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Done => 'Done',
        };
    }
}
