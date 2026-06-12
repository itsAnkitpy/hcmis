<?php

declare(strict_types=1);

namespace App\Events\Telephony;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The keepable artifact exists: the merged stereo MP3 (caller left, agent
 * right — PW5-4) landed on the recordings disk. Dispatched by the merge job,
 * not the listener — the file is only "ready" once it is actually stored.
 */
class RecordingReady
{
    use Dispatchable;

    public function __construct(
        public string $callId,
        public string $recordingName,
        public string $disk,
        public string $path,
    ) {}
}
