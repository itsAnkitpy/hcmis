<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\Telephony\RecordingReady;
use App\Models\Call;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * CP-B3-2 (D3/D6): attach the merged recording to its `calls` row — the listener's
 * ONLY B3 job. It ENRICHES, never writes a row (D2): the web wrap-up already wrote
 * the row in tenant context, stamping the call's UUID as correlation_id, and
 * RecordingReady carries that same UUID as its callId (the flow threaded args[2]
 * onto the recording). So the match is exact — no fuzzy time/number matching.
 *
 * THE TENANT SEAM (the one risk traced at build, S40): the merge pipeline does NOT
 * carry a tenant id — the flow that spawns it (CallToAgentFlow) has no tenant
 * context at all (B1 D3), so there is none to thread. We DISCOVER the tenant from
 * the row instead: the UUID is globally unique, so one unscoped lookup reads the
 * row's tenant_id, then the stamp runs scoped to that tenant so RLS backstops the
 * write (the ImportLeadsJob tenant-write precedent). The discovery read uses
 * runGlobal (the ownerless read posture) — NOT cross(), which would fire the
 * tenant.cross_access tripwire on every recorded call and drown that signal.
 *
 * THE RACE (D3): the merge (~1-2s) usually finishes BEFORE the agent picks a
 * disposition (5-30s), so the row often is not written yet. Queued with bounded
 * retry: row-not-found -> release() with backoff over ~the retry window; past it
 * the recording is left orphaned on disk (acceptable v1 — the file persists for a
 * later reconciliation) and logged, not failed. Order-independent, standard Laravel.
 */
class AttachRecordingToCall implements ShouldQueue
{
    use InteractsWithQueue;

    /** ~60s of retries (12 attempts x 5s) to outlast the disposition-pick race. */
    public int $tries = 12;

    public int $backoff = 5;

    public function handle(RecordingReady $event): void
    {
        // The recording's id must be a UUID we own to have a `calls` row to attach to.
        // Outbound carries the web's tracking UUID; inbound now carries the call's TICKET
        // (also a UUID), the SAME id the screen stamped on the row via the listener->
        // browser handoff (B2.4b TH-4) — so this guard now passes BOTH directions. A
        // genuine non-UUID id (a stray lab/raw-leg recording with no matching row) is
        // still skipped here without burning retries.
        if (! Str::isUuid($event->callId)) {
            return;
        }

        // Discover the owning tenant from the row (the merge pipeline carries none).
        $tenantId = TenantContext::runGlobal(
            fn (): ?int => Call::query()->where('correlation_id', $event->callId)->value('tenant_id'),
        );

        if ($tenantId === null) {
            // The row is not written yet (the race) — retry within the bound. Past
            // it, leave the recording orphaned on disk rather than failing the job.
            if ($this->attempts() >= $this->tries) {
                Log::warning('Recording orphaned: no calls row for the correlation id within the retry window.', [
                    'correlation_id' => $event->callId,
                ]);

                return;
            }

            $this->release($this->backoff);

            return;
        }

        // Stamp the recording scoped to the owning tenant so RLS backstops the
        // write and the `call.updated` enrichment is audited in the right context.
        TenantContext::run($tenantId, function () use ($event): void {
            Call::query()
                ->where('correlation_id', $event->callId)
                ->first()
                ?->update([
                    'recording_disk' => $event->disk,
                    'recording_path' => $event->path,
                ]);
        });
    }
}
