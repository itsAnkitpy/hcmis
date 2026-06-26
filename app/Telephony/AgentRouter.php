<?php

declare(strict_types=1);

namespace App\Telephony;

use App\Enums\PresenceStatus;
use App\Models\AgentPresence;
use App\Tenancy\TenantContext;

/**
 * Pick a free agent among several, race-proof (B2.2b RD-3/RD-4). The watcher's
 * board-reader + reservation: read a company's who's-free board, choose the first
 * free agent, and TAG them "taken" the instant we ring them so a second call that
 * lands in the same split-second skips them. Reserved-at-ring is the only board
 * write the watcher ever does (PD-3); everything else is the screen's.
 *
 * THE TENANT SEAM (the AttachRecordingToCall precedent). The listener has no tenant
 * context (B1 D3); the call carries its company as a label (RD-1). We already KNOW
 * the tenant from that label, so unlike AttachRecordingToCall we skip the runGlobal
 * DISCOVERY step and go straight to a scoped run — the board read + the reservation
 * write both happen inside TenantContext::run($tenantId), so the BelongsToTenant
 * global scope walls them to that one company (a stray label can never read or
 * write another company's board).
 *
 * THE RACE (the proven B2.0 grab, reapplied). The reservation is the claimCallback
 * shape — an atomic conditional update "tag this agent on_call, ONLY IF still ready
 * (and fresh)": rows-affected = 1 means we won; 0 means another call beat us to this
 * agent, so we move to the next free one. No row locks held by hand.
 *
 * THE TAG is the existing "On a call" status — no new status (PD-2 folded being-rung
 * into On a call). The release (Fold B) is just as conditional: it flips back to
 * Ready ONLY IF the row is still the on_call WE set, so a no-answer release can never
 * stamp over a status the agent's own screen legitimately set in the meantime.
 */
class AgentRouter
{
    /**
     * Reserve the first free agent on this company's board for an incoming call
     * (RD-3 first-free, RD-4 reserved-at-ring). Reads Ready + fresh-heartbeat agents
     * in a deterministic order, then atomically tags each in turn until one sticks —
     * returning the reserved agent's user id, or null when nobody is free (RD-5).
     *
     * The atomic tag re-checks Ready + freshness in its own WHERE, so it is correct
     * even if a candidate went stale or was grabbed between the read and the tag.
     */
    public function reserveFreeAgent(int $tenantId): ?int
    {
        return TenantContext::run($tenantId, function (): ?int {
            $freshThreshold = now()->subSeconds(
                (int) config('telephony.presence.stale_after_seconds'),
            );

            $candidates = AgentPresence::query()
                ->where('status', PresenceStatus::Ready->value)
                ->where('last_seen_at', '>=', $freshThreshold)
                ->orderBy('user_id')
                ->pluck('user_id');

            foreach ($candidates as $userId) {
                $reserved = AgentPresence::query()
                    ->where('user_id', $userId)
                    ->where('status', PresenceStatus::Ready->value)
                    ->where('last_seen_at', '>=', $freshThreshold)
                    ->update(['status' => PresenceStatus::OnCall->value]);

                if ($reserved === 1) {
                    return (int) $userId;
                }
            }

            return null;
        });
    }

    /**
     * Release a reservation when the agent never connected (Fold B): the no-answer
     * the watcher directly observes — the agent rang out, OR the caller abandoned
     * mid-ring. CONDITIONAL on purpose: flips on_call -> ready ONLY IF the row is
     * still the on_call we set, so it never overwrites a Break / Wrap-up the agent's
     * own screen set during the ring (the screen stays the authority on those).
     */
    public function releaseReservation(int $tenantId, int $userId): void
    {
        TenantContext::run($tenantId, function () use ($userId): void {
            AgentPresence::query()
                ->where('user_id', $userId)
                ->where('status', PresenceStatus::OnCall->value)
                ->update(['status' => PresenceStatus::Ready->value]);
        });
    }
}
