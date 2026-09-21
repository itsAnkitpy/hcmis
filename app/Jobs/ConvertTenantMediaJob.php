<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\TenantMedia;
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
 * Turn one of a client's uploaded sounds into something a phone line can carry
 * (inbound-audio slice 3 steps 2/3/5, reused by slice 4).
 *
 * ONE JOB FOR EVERY SOUND (slice 4, Q2). The two paths differ in three values — which
 * columns the file lands in, whether a second of silence is appended, and whether the
 * voice box's music table gets a row — and in nothing else. The kind carries those
 * values, so a second sound is an enum case, not a second copy of this file.
 *
 * Queued for the same reason the recording merge is (D5): nobody is on hold waiting
 * for this, and audio conversion must never run inside the listener. Converting on
 * the APP server, not the voice box — F3, the tool is already here.
 *
 * THE NAME IS THE CONTENT (step 3). The converted file is stored under its own
 * SHA-256, so a re-upload is always a new name, a new web address, and therefore a
 * new cache key on the voice box, which holds a fetched file against its address for
 * a year. A stable address would keep the replaced sound playing.
 */
class ConvertTenantMediaJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Tenant $tenant,
        public TenantMedia $kind,
        public string $uploadDisk,
        public string $uploadPath,
    ) {}

    public function handle(HoldMusicWriter $writer): void
    {
        $uploads = Storage::disk($this->uploadDisk);
        $media = Storage::disk(config('telephony.media.disk'));

        $extension = pathinfo($this->uploadPath, PATHINFO_EXTENSION) ?: 'mp3';
        $directory = sys_get_temp_dir();
        $sourcePath = "{$directory}/{$this->kind->value}-{$this->tenant->id}-source.{$extension}";
        $convertedPath = "{$directory}/{$this->kind->value}-{$this->tenant->id}.wav";

        try {
            file_put_contents($sourcePath, (string) $uploads->get($this->uploadPath));

            // 8 kHz mono 16-bit WAV — the shape S165 check 7 proved `sox` produces from a
            // 44.1 kHz stereo MP3 on this very server. sox reads the input's format from
            // its extension, which is why the temp copy above keeps the uploaded one.
            //
            // `pad 0 1` rides on the end for SPEECH only (see TenantMedia::padSeconds):
            // it appends silence AFTER the resample, leaving the format untouched, so a
            // handset cannot clip the last word. Music gets none — it loops.
            $pad = $this->kind->padSeconds();

            Process::run([
                'sox', $sourcePath, '-r', '8000', '-c', '1', '-b', '16', $convertedPath,
                ...($pad > 0 ? ['pad', '0', (string) $pad] : []),
            ])->throw();

            $converted = (string) file_get_contents($convertedPath);
            $path = $this->kind->pathFor($this->tenant->id, hash('sha256', $converted));
            $previousPath = $this->tenant->{$this->kind->pathColumn()};

            $media->put($path, $converted);

            $this->tenant->update([$this->kind->pathColumn() => $path]);

            // Only hold music has a row in the voice box's own tables, because only hold
            // music is looked up BY NAME there. Every other sound is played by a direct
            // order carrying its web address, so there is nothing to write (slice 4).
            if ($this->kind === TenantMedia::HoldMusic) {
                $writer->writeFor($this->tenant);
            }

            // The old converted file can never be asked for again: its address carried the
            // old content hash, and the row the voice box reads now holds the new one.
            if (filled($previousPath) && $previousPath !== $path) {
                $media->delete($previousPath);
            }

            $uploads->delete($this->uploadPath);
        } finally {
            @unlink($sourcePath);
            @unlink($convertedPath);
        }
    }

    /**
     * A failure leaves the client's previous sound in place and the raw upload on disk
     * for a retry — silence here would mean a client whose new file simply never
     * appeared, with nothing saying why.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('Tenant media: converting the client\'s upload failed; their previous file (or the default) is still in use.', [
            'tenant' => $this->tenant->id,
            'kind' => $this->kind->value,
            'upload' => $this->uploadPath,
            'why' => $exception?->getMessage() ?? 'media conversion failed',
        ]);
    }
}
