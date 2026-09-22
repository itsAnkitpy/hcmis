<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TenantMedia;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serve one of a client's uploaded sounds to the voice box (inbound-audio AU-12 /
 * AUQ-4). The files sit on a private disk, so this route is the only way one leaves
 * the server — the same posture as CallRecordingController, but it can borrow none of
 * that controller's guards, because the fetcher has no login.
 *
 * ONE CONTROLLER FOR EVERY SOUND (slice 4, Q1). Slice 3 shipped this hold-music-shaped,
 * and slices 4, 5 and 8 each add another sound — four near-identical controllers. The
 * kind rides in the address as its FIRST segment, which is exactly where slice 3 already
 * put the literal word `hold-music`, so the music's existing addresses are unchanged to
 * the byte: the signature still matches, the voice box's year-old cached copy is still
 * valid, and the address sitting in Asterisk's `musiconhold_entry` row still resolves.
 *
 * THE GUARDS, in the order they apply:
 *
 *  1. `signed` (route middleware). The address carries an HMAC of itself, so it
 *     cannot be guessed or edited — not the kind, not the client id, not the file.
 *     Laravel signs the whole address, path segments included (UrlGenerator::
 *     hasCorrectSignature reads $request->url()), so the kind segment is covered by
 *     the same signature with nothing extra to do. PERMANENT on purpose (no expiry):
 *     the address is stored in Asterisk's row and the voice box caches the file
 *     against it for a year. An expiring address would have to be rewritten on a
 *     schedule and would re-download the file every rotation (S165: the cache key IS
 *     the address).
 *  2. WHO may fetch — one of:
 *       - a request from a configured voice-box address (`media.fetch_ips`).
 *         Empty list = signature only, which is how local and the test suite run.
 *       - a signed-in head-office user who may edit this client (AU-8), which is
 *         the Filament form's own play button (AU-9) — Filament has no audio
 *         player, so the form renders a plain <audio> tag at this same address.
 *  3. The file is still IN USE, and the check differs by who owns it.
 *
 *     For a CLIENT's own sound, unchanged since slice 3: it must be the file this
 *     client currently has for that kind. Content naming means a re-upload is a new
 *     address; an old address 404s rather than quietly serving a sound the client has
 *     replaced — and it 404s even if the replaced file is still sitting on the disk.
 *
 *     🔴 A MENU'S SOUNDS CANNOT BE ASKED THAT QUESTION (slice 6). One client may have
 *     several menus and one menu several sounds, so there is no fixed column to compare
 *     against. For those the check is that the file is still on the disk — the shape a
 *     presigned link on S3 or Cloud Storage already has, where the signature is the
 *     capability and the store serves the object if it is still there.
 *
 *     🔴 WHICH MAKES DELETING THE FILE THE ONLY REVOCATION FOR A MENU SOUND. Menu::
 *     booted does it on every way one stops being used — the menu deleted, a key
 *     removed, a sound replaced — because these addresses never expire (AUQ-4).
 *
 *     Kept as a BRANCH rather than made uniform, and that was a correction: dropping
 *     the client-column read everywhere looked free, and three tests proved it was not.
 *     A client sound whose file lingers on the disk after the row moved on would have
 *     gone on being served. The stronger check still works where it works, so it stays.
 *
 * TWO HEADERS THE VOICE BOX ACTUALLY READS (measured on staging, S165):
 *  - `Content-Type: audio/wav` — it picks the file's format from this FIRST and
 *    from the address's ending only if this is missing (20.6.0 res_http_media_cache).
 *  - `Cache-Control: max-age` — while it holds, the box replays its copy with no
 *    HTTP request at all. Without it (and Laravel sends no ETag of its own:
 *    Symfony's BinaryFileResponse has $autoEtag = false) it re-downloads the file
 *    on EVERY play. A year is safe precisely because the address is content-named:
 *    this address's bytes can never change.
 */
class TenantMediaController extends Controller
{
    private const CACHE_SECONDS = 31536000;

    public function __invoke(Request $request, string $kind, int $tenant, string $hash): Response
    {
        // The route constrains {kind} to the case values, so an unknown one never
        // reaches here — but the enum, not the regex, stays the authority on the list.
        $media = TenantMedia::tryFrom($kind);

        abort_if($media === null, 404);

        $client = Tenant::query()->findOrFail($tenant);

        abort_unless($this->mayFetch($request, $client), 403);

        // The address IS the path: the kind, the client and the content hash are all
        // signed, so this reconstruction cannot be steered anywhere else.
        $path = $media->pathFor($tenant, $hash);

        // A client's own sound still has to BE the client's current one for that kind.
        // A menu's has no such column, so its only check is the disk, below.
        if ($media->isClientOwned()) {
            abort_unless($client->{$media->pathColumn()} === $path, 404);
        }

        $disk = Storage::disk(config('telephony.media.disk'));

        abort_unless($disk->exists($path), 404);

        return response()->file($disk->path($path), [
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
        $allowed = config('telephony.media.fetch_ips');

        if ($allowed === [] || in_array((string) $request->ip(), $allowed, true)) {
            return true;
        }

        return $request->user() !== null && Gate::forUser($request->user())->allows('update', $client);
    }
}
