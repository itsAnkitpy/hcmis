<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\TenantMedia;
use App\Models\Menu;
use App\Models\Tenant;
use App\Telephony\HoldMusicWriter;
use App\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
 * SLICE 6 ADDS AN OWNER, NOT A SECOND JOB. A menu's greeting and its per-key sounds
 * are converted exactly like a client's, but land on a MENU row rather than a client
 * column, because one client may have several menus. `$menuId` says so; `$optionKey`
 * picks the key inside it, or null for the greeting. Everything above this line —
 * the conversion, the padding, the content naming, the cache reasoning — is shared.
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
        public ?int $menuId = null,
        public ?string $optionKey = null,
    ) {}

    public function handle(HoldMusicWriter $writer): void
    {
        $uploads = Storage::disk($this->uploadDisk);
        $media = Storage::disk(config('telephony.media.disk'));

        $extension = pathinfo($this->uploadPath, PATHINFO_EXTENSION) ?: 'mp3';
        $directory = sys_get_temp_dir();
        // 🔴 UNIQUE PER JOB, not per client and kind. One menu save can queue several
        // uploads of the SAME kind for the SAME client — one per key — and a shared temp
        // name would have two of them writing over each other mid-conversion. The random
        // tail is not the client's business, but the EXTENSION is: sox reads the input's
        // format from it, so the source copy keeps the uploaded one.
        $stem = "{$this->kind->value}-{$this->tenant->id}-".Str::random(8);
        $sourcePath = "{$directory}/{$stem}-source.{$extension}";
        $convertedPath = "{$directory}/{$stem}.wav";

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

            $media->put($path, $converted);

            // A menu's sounds are swept by the menu itself, which covers the removals no
            // job ever sees (a key deleted on the edit form) as well as this replacement.
            // A client's sound has no such owner, so its previous file is deleted below.
            $previousPath = null;

            if ($this->menuId !== null) {
                // 🔴 THE ID, NOT THE ROW, and looked up inside the client's context —
                // ImportLeadsJob's seam. A worker has no client selected, so carrying the
                // menu itself made the queue restore a row the client wall hides, and the
                // job died before it ever converted anything. Found on staging, S170;
                // tests run this job inline, where the request's context is still set.
                TenantContext::run(
                    $this->tenant->id,
                    fn () => Menu::findOrFail($this->menuId)->storeSoundPath($this->optionKey, $path),
                );
            } else {
                $previousPath = $this->tenant->{$this->kind->pathColumn()};
                $this->tenant->update([$this->kind->pathColumn() => $path]);
            }

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
            'menu' => $this->menuId,
            'option' => $this->optionKey,
            'upload' => $this->uploadPath,
            'why' => $exception?->getMessage() ?? 'media conversion failed',
        ]);
    }
}
