<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serve a client's converted hold music to the voice box (inbound-audio slice 3,
 * AU-12 / AUQ-4). The file sits on a private disk, so this route is the only way
 * it leaves the server — the same posture as CallRecordingController, but it can
 * borrow none of that controller's guards, because the fetcher has no login.
 *
 * THE GUARDS, in the order they apply:
 *
 *  1. `signed` (route middleware). The address carries an HMAC of itself, so it
 *     cannot be guessed or edited — not the client id, not the file. PERMANENT on
 *     purpose (no expiry): the address is stored in Asterisk's `musiconhold_entry`
 *     row and the voice box caches the file against it for a year. An expiring
 *     address would have to be rewritten on a schedule and would re-download the
 *     music every time it rotated (S165: the cache key IS the address).
 *  2. WHO may fetch — one of:
 *       - a request from a configured voice-box address (`hold_music.fetch_ips`).
 *         Empty list = signature only, which is how local and the test suite run.
 *       - a signed-in head-office user who may edit this client (AU-8), which is
 *         the Filament form's own play button (AU-9) — Filament has no audio
 *         player, so the form renders a plain <audio> tag at this same address.
 *  3. The file in the address must be the one the client currently has. Content
 *     naming means a re-upload is a new address; an old address 404s rather than
 *     quietly serving music the client has replaced.
 *
 * TWO HEADERS THE VOICE BOX ACTUALLY READS (measured on staging, S165):
 *  - `Content-Type: audio/wav` — it picks the file's format from this FIRST and
 *    from the address's ending only if this is missing (20.6.0 res_http_media_cache).
 *  - `Cache-Control: max-age` — while it holds, the box replays its copy with no
 *    HTTP request at all. Without it (and Laravel sends no ETag of its own:
 *    Symfony's BinaryFileResponse has $autoEtag = false) it re-downloads the file
 *    on EVERY hold start. A year is safe precisely because the address is content-
 *    named: this address's bytes can never change.
 */
class HoldMusicController extends Controller
{
    private const CACHE_SECONDS = 31536000;

    public function __invoke(Request $request, int $tenant, string $hash): Response
    {
        $client = Tenant::query()->findOrFail($tenant);

        abort_unless($this->mayFetch($request, $client), 403);

        abort_unless(filled($client->hold_music_path), 404);

        abort_unless($client->hold_music_path === "hold-music/{$tenant}/{$hash}.wav", 404);

        $disk = Storage::disk(config('telephony.hold_music.disk'));

        abort_unless($disk->exists($client->hold_music_path), 404);

        return response()->file($disk->path($client->hold_music_path), [
            'Content-Type' => 'audio/wav',
            'Cache-Control' => 'public, max-age='.self::CACHE_SECONDS,
        ]);
    }

    /**
     * Guard 2. The voice box by address, or head office listening to its own upload.
     */
    private function mayFetch(Request $request, Tenant $client): bool
    {
        /** @var array<int, string> $allowed */
        $allowed = config('telephony.hold_music.fetch_ips');

        if ($allowed === [] || in_array((string) $request->ip(), $allowed, true)) {
            return true;
        }

        return $request->user() !== null && Gate::forUser($request->user())->allows('update', $client);
    }
}
