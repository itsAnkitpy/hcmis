<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Audit\Audit;
use App\Models\Call;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
 * Every successful access is audited (CR-5): customer-voice audio is PII (FR-QC05).
 */
class CallRecordingController extends Controller
{
    public function __invoke(Request $request, string $record): StreamedResponse
    {
        $call = Call::query()->findOrFail($record);

        Gate::authorize('view', $call);

        abort_unless(filled($call->recording_disk) && filled($call->recording_path), 404);

        $disk = Storage::disk($call->recording_disk);

        abort_unless($disk->exists($call->recording_path), 404);

        $download = $request->boolean('download');

        Audit::recordingAccessed($call, $download ? 'download' : 'play');

        $filename = "call-{$call->id}.mp3";

        return $download
            ? $disk->download($call->recording_path, $filename)
            : $disk->response($call->recording_path, $filename, ['Content-Type' => 'audio/mpeg']);
    }
}
