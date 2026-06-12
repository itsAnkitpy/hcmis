<?php

declare(strict_types=1);

namespace App\Telephony;

/**
 * Handle for one in-flight per-side recording (B1 D4): two silent listener
 * legs ("snoops") on the same call leg — one taping what that person says,
 * one taping what they hear. stopRecording() needs all the ids back, so
 * they travel together.
 */
final readonly class RecordingSession
{
    public function __construct(
        public string $legId,
        public string $name,
        public string $saidSnoopLegId,
        public string $heardSnoopLegId,
    ) {}

    public function saidRecordingName(): string
    {
        return "{$this->name}-said";
    }

    public function heardRecordingName(): string
    {
        return "{$this->name}-heard";
    }
}
