<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\Telephony\RecordingFailed;
use App\Events\Telephony\RecordingReady;
use App\Telephony\TelephonyProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The off-the-voice-box half of recording (B1 D4): pull the two per-side WAVs
 * over HTTP, merge them into one compressed stereo MP3 — caller's voice left,
 * what the caller heard right (PW5-4) — and store it on the recordings disk.
 *
 * Queued on purpose (D5's dividing rule): nobody is waiting on hold for the
 * merge, so it must not run inside the listener. Encoding off the voice box
 * is also the production shape — the call path never pays for MP3 encoding.
 */
class MergeCallRecordingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $callId,
        public string $recordingName,
    ) {}

    public function handle(TelephonyProvider $telephony): void
    {
        $directory = sys_get_temp_dir();
        $saidPath = "{$directory}/{$this->recordingName}-said.wav";
        $heardPath = "{$directory}/{$this->recordingName}-heard.wav";
        $mp3Path = "{$directory}/{$this->recordingName}.mp3";

        try {
            file_put_contents($saidPath, $telephony->fetchRecording("{$this->recordingName}-said"));
            file_put_contents($heardPath, $telephony->fetchRecording("{$this->recordingName}-heard"));

            // -M merges each input into its own channel: first = left = the
            // caller's voice, second = right = what the caller heard. Same
            // sox recipe as lab A2 (~0.24 MB/min at 32 kbps).
            Process::run(['sox', '-M', $saidPath, $heardPath, '-C', '32.2', $mp3Path])->throw();

            $disk = config('telephony.recordings.disk');
            $path = "recordings/{$this->recordingName}.mp3";

            Storage::disk($disk)->put($path, (string) file_get_contents($mp3Path));

            RecordingReady::dispatch($this->callId, $this->recordingName, $disk, $path);
        } finally {
            @unlink($saidPath);
            @unlink($heardPath);
            @unlink($mp3Path);
        }
    }

    public function failed(?Throwable $exception): void
    {
        RecordingFailed::dispatch(
            $this->recordingName,
            $exception?->getMessage() ?? 'recording merge failed',
        );
    }
}
