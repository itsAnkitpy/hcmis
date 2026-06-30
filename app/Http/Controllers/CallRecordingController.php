<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Audit\Audit;
use App\Models\Call;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serve a call recording (Call Review CR-2) — the ONLY way the private audio file
 * leaves the server. The file lives on a non-public disk, so there is no direct
 * URL; this gated route is it.
 *
 * Three guards, in order:
 *  1. `auth` + SetCurrentTenant (the route middleware) put the request in the
 *     viewer's tenant context, so the call lookup below runs under RLS — a foreign
 *     client's call id simply is not found (404). That IS the ownership check
 *     (global staff in the cross-tenant posture intentionally reach any client's
 *     call, audited like everything else they do). The lookup is done HERE, in the
 *     controller, not via route-model binding, so it is guaranteed to run AFTER the
 *     tenant GUC is set (no middleware-ordering dependence on SubstituteBindings).
 *  2. the `view` ability (CallPolicy) — only the call auditors (global + TL/QC).
 *  3. the recording must actually exist on disk (else 404 — inbound v1 has no
 *     recording, and retention may have pruned an old file).
 *
 * The inline-play branch is served through Symfony's BinaryFileResponse (HD-2),
 * which answers Range requests with 206 partial content — so the player's progress
 * line can seek, and Safari (which refuses <audio> without range) will play.
 *
 * Access is audited (CR-5): customer-voice audio is PII (FR-QC05). One listen is one
 * trail entry, not one-per-slice — range makes a single listen hit this route several
 * times, so we audit only the listen-START request (HD-3), and a download (one
 * deliberate click) is always one entry.
 */
class CallRecordingController extends Controller
{
    public function __invoke(Request $request, string $record): Response
    {
        $call = Call::query()->findOrFail($record);

        Gate::authorize('view', $call);

        abort_unless(filled($call->recording_disk) && filled($call->recording_path), 404);

        $disk = Storage::disk($call->recording_disk);

        abort_unless($disk->exists($call->recording_path), 404);

        $filename = "call-{$call->id}.mp3";

        if ($request->boolean('download')) {
            Audit::recordingAccessed($call, 'download');

            return $disk->download($call->recording_path, $filename);
        }

        // HD-3: count one listen as one PII-access entry. A seek (or Safari's
        // mid-file probe) re-hits this route with `Range: bytes=N-` (N > 0); only the
        // start of a listen carries no Range header or a range from byte 0.
        if ($this->isListenStart($request)) {
            Audit::recordingAccessed($call, 'play');
        }

        // HD-2: BinaryFileResponse (via response()->file) auto-answers `Range` → 206 +
        // `Content-Range`, and sets `Accept-Ranges: bytes`. path() is local-driver
        // only; an S3 future swaps this for a redirect to a signed URL (range native).
        return response()->file($disk->path($call->recording_path), ['Content-Type' => 'audio/mpeg']);
    }

    /**
     * Is this request the start of a listen (vs a mid-file seek / Safari continuation)?
     * A fresh play sends no `Range` header, or one starting at byte 0 (`bytes=0-...`);
     * a scrub sends `bytes=N-` with N > 0.
     */
    private function isListenStart(Request $request): bool
    {
        $range = $request->header('Range');

        return $range === null || preg_match('/^bytes=0-/', $range) === 1;
    }
}
