<?php

declare(strict_types=1);

namespace App\Events\Telephony;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A recording did not make it — the engine refused it after accepting the
 * request (the async-refusal lesson from the 2026-06-12 experiment), never
 * confirmed it finished, or the stereo merge fell over.
 */
class RecordingFailed
{
    use Dispatchable;

    public function __construct(
        public string $recordingName,
        public string $reason,
    ) {}
}
