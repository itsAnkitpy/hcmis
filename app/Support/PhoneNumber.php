<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Single source of truth for turning a raw phone value into the normalized form
 * the system stores and matches on. Extracted from LeadsImport (M5) so lead
 * phones and DNC phones normalize identically — Phase-3 DNC scrubbing compares
 * the two, so they must agree (M6 D-M6-5).
 *
 * Light normalization only: trim, then strip spaces, dashes and brackets. No
 * country-code logic — voice is not live in Phase 1, phone is a data field only
 * (Phase-1 guardrail #1).
 */
class PhoneNumber
{
    /**
     * Normalize a raw value to a bare phone string, or null if it is unusable.
     */
    public static function normalize(mixed $raw): ?string
    {
        if (! is_string($raw) && ! is_int($raw)) {
            return null;
        }

        $normalized = preg_replace('/[\s\-()]/', '', trim((string) $raw));

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Whether a normalized phone looks dialable: 7–15 digits, optional leading +.
     */
    public static function isValid(string $phone): bool
    {
        return preg_match('/^\+?\d{7,15}$/', $phone) === 1;
    }
}
