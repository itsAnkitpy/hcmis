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

    /**
     * ~10 minutes of retries (60 attempts x 10s) to outlast the wrap-up.
     *
     * 🔴 It used to be ~60s (12 x 5s), sized against the docblock's estimate that an
     * agent picks a disposition in 5-30 seconds. Staging says otherwise: a real call on
     * 2026-08-12 was orphaned in the SAME SECOND its row was written, because the agent
     * spent 69 seconds on the wrap-up screen — and the log carries the same warning on
     * 2026-08-05 and 2026-08-11. **Recordings had been going missing for over a week
     * whenever anybody typed unhurried notes, with nothing to say so.** Wrap-up has no
     * time limit and never did; only this bound pretended it had one.
     *
     * The first attempt is immediate, so the ordinary case (the row already exists)
     * still attaches at once. The retries only cost a sleeping job.
     *
     * ponytail: a fixed 10-minute bound, not a real fix for an unbounded wait — an agent
     * who takes longer still loses the audio, silently. The proper fix is for the row to
     * find the recording rather than the recording to wait for the row (the CT-15 shape),
     * which needs the orphan parked somewhere the wrap-up can look. Do that if this bound
     * is ever hit in earnest; the log warning is the signal.
     */
    public int $tries = 60;

    public int $backoff = 10;

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
        //
        // EVERY matching row, not the first one found (CT-13). A transferred or
        // conferenced call now leaves TWO rows carrying the same ticket — the fresh note
        // CT-5 hands the second agent is what makes them share it — and this query had
        // no ordering, so the audio landed on one of them at random and the other looked
        // like it had none. One recording of one call; whoever opens either half can
        // play it. Our recording rides the caller's leg and that leg never moves
        // (TD-3/TD-6), so it is one continuous file covering both agents; splitting it
        // per agent is the industry's answer and is its own slice.
        //
        // ⤳ This alone does not finish the job: on a real transfer the second agent's
        // row does not EXIST yet when this runs (they are still typing their notes),
        // so it is the wrap-up that back-fills from its sibling — see CT-15 in
        // AgentConsole::siblingRecording().
        TenantContext::run($tenantId, function () use ($event): void {
            Call::query()
                ->where('correlation_id', $event->callId)
                ->update([
                    'recording_disk' => $event->disk,
                    'recording_path' => $event->path,
                ]);
        });
    }
}
