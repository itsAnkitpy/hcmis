<?php

namespace App\Enums;

/**
 * Why a phone number sits on a client's own do-not-call list (FR-LC05).
 *
 * The national TRAI DND register lives in a separate, non-tenant table
 * (national_dnc_entries), so trai_dnd is intentionally NOT a value here — a
 * client never hand-adds a TRAI-sourced number; that arrives via the Phase-3
 * subscription import (M6 D-M6-1/-3).
 */
enum DncSource: string
{
    case CustomerRequest = 'customer_request';
    case Manual = 'manual';
    case Legal = 'legal';

    public function label(): string
    {
        return match ($this) {
            self::CustomerRequest => 'Customer request',
            self::Manual => 'Manual',
            self::Legal => 'Legal',
        };
    }
}
