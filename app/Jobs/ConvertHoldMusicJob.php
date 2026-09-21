<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Tenant;
use App\Telephony\HoldMusicWriter;
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
 * Turn a client's uploaded music into something a phone line can carry, then tell
 * the voice box where to find it (inbound-audio slice 3, steps 2, 3 and 5).
 *
 * Queued for the same reason the recording merge is (D5): nobody is on hold waiting
 * for this, and audio conversion must never run inside the listener. Converting on
 * the APP server, not the voice box — F3, the tool is already here.
 *
 * NO TRAILING SILENCE, deliberately. S165 found that a spoken greeting loses its last
 * word on a real handset unless about a second of silence is appended (`sox … pad 0 1`),
 * and that belongs to slice 4's closed message. Hold music LOOPS — the voice box
 * cycles a playlist back to its first entry for as long as the caller waits — so a
 * second of appended silence would be a hiccup on every lap. Music has no last word
 * to lose.
 *
 * THE NAME IS THE CONTENT (step 3). The converted file is stored under its own
 * SHA-256, so a re-upload is always a new name, a new web address, and therefore a
 * new cache key on the voice box, which holds a fetched file against its address for
 * a year. A stable address would keep the replaced music playing.
 */
class ConvertHoldMusicJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Tenant $tenant,
        public string $uploadDisk,
        public string $uploadPath,
    ) {}

    public function handle(HoldMusicWriter $writer): void
    {
        $uploads = Storage::disk($this->uploadDisk);
        $music = Storage::disk(config('telephony.hold_music.disk'));

        $extension = pathinfo($this->uploadPath, PATHINFO_EXTENSION) ?: 'mp3';
        $directory = sys_get_temp_dir();
        $sourcePath = "{$directory}/hold-music-{$this->tenant->id}-source.{$extension}";
        $convertedPath = "{$directory}/hold-music-{$this->tenant->id}.wav";

        try {
            file_put_contents($sourcePath, (string) $uploads->get($this->uploadPath));

            // 8 kHz mono 16-bit WAV — the shape S165 check 7 proved `sox` produces from a
            // 44.1 kHz stereo MP3 on this very server. sox reads the input's format from
            // its extension, which is why the temp copy above keeps the uploaded one.
            Process::run([
                'sox', $sourcePath, '-r', '8000', '-c', '1', '-b', '16', $convertedPath,
            ])->throw();

            $converted = (string) file_get_contents($convertedPath);
            $path = "hold-music/{$this->tenant->id}/".hash('sha256', $converted).'.wav';
            $previousPath = $this->tenant->hold_music_path;

            $music->put($path, $converted);

            $this->tenant->update(['hold_music_path' => $path]);

            $writer->writeFor($this->tenant);

            // The old converted file can never be asked for again: its address carried the
            // old content hash, and the row the voice box reads now holds the new one.
            if (filled($previousPath) && $previousPath !== $path) {
                $music->delete($previousPath);
            }

            $uploads->delete($this->uploadPath);
        } finally {
            @unlink($sourcePath);
            @unlink($convertedPath);
        }
    }

    /**
     * A failure leaves the client's previous music playing and the raw upload on disk
     * for a retry — silence here would mean a client whose new music simply never
     * appeared, with nothing saying why.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('Hold music: converting the client\'s upload failed; their previous music (or the default) still plays.', [
            'tenant' => $this->tenant->id,
            'upload' => $this->uploadPath,
            'why' => $exception?->getMessage() ?? 'hold music conversion failed',
        ]);
    }
}
