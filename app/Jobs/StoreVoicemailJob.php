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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The message pad's half of MergeCallRecordingJob (inbound-audio slice 8): pull the
 * caller's one WAV off the voice box, compress it to MP3, store it beside the call
 * recordings, and announce it.
 *
 * 🔴 IT ENDS WHERE A CALL RECORDING ENDS. RecordingReady carries the call's ticket, and
 * AttachRecordingToCall stamps the file on the row whose `correlation_id` is that ticket
 * — the missed row, written before the message began (S174 decision 2). So the message
 * reaches Missed Calls through the same audited write, with no code of its own.
 *
 * No client context needed: nothing here touches the database. The listener discovers
 * the client from the row, the same seam call recordings use.
 */
class StoreVoicemailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Retried with growing waits, about 3.5 minutes in all (review S175 F5). The worker's
     * own default is no wait at all (`--backoff=0`), so one Asterisk reload could eat
     * every try. Safe to repeat: the source stays on the voice box and the path is fixed.
     */
    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60, 120];

    public function __construct(
        public string $callId,
        public string $recordingName,
    ) {}

    public function handle(TelephonyProvider $telephony): void
    {
        $directory = sys_get_temp_dir();
        $wavPath = "{$directory}/{$this->recordingName}.wav";
        $mp3Path = "{$directory}/{$this->recordingName}.mp3";

        try {
            file_put_contents($wavPath, $telephony->fetchRecording($this->recordingName));

            // The call recordings' own sox recipe, one channel instead of two.
            Process::run(['sox', $wavPath, '-C', '32.2', $mp3Path])->throw();

            $disk = config('telephony.recordings.disk');
            $path = "recordings/{$this->recordingName}.mp3";

            Storage::disk($disk)->put($path, (string) file_get_contents($mp3Path));

            RecordingReady::dispatch($this->callId, $this->recordingName, $disk, $path);
        } finally {
            @unlink($wavPath);
            @unlink($mp3Path);
        }
    }

    /**
     * Nothing listens to RecordingFailed, so the log line is what tells anyone. The
     * message is still on the voice box: re-dispatch this job by hand (inbound-audio.md,
     * slice 8, review S175 F4).
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('Voicemail: the message could not be stored — it is still on the voice box; re-dispatch StoreVoicemailJob by hand.', [
            'ticket' => $this->callId,
            'recording' => $this->recordingName,
            'error' => $exception?->getMessage(),
        ]);

        RecordingFailed::dispatch(
            $this->recordingName,
            $exception?->getMessage() ?? 'voicemail store failed',
        );
    }
}
