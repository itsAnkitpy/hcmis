<?php

declare(strict_types=1);

namespace App\Telephony;

use App\Enums\PresenceStatus;
use App\Models\AgentPresence;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

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
     *
     * $skipUserIds is B2.3b-i QD-4's per-call skip list: agents this ONE caller has
     * already been rung out on. Without it a waiting caller cycles forever between
     * music and the same silent desk — the agent's phone rings out, they are still
     * "Ready" on the board, and the next sweep picks them again. The skip is per
     * call and lives on the call's handler, deliberately NOT a board-wide "pause
     * this agent" (that needs a sixth presence state and a screen to clear it).
     *
     * 🔴 SEC-1 slice 4: AN AGENT WITH NO PHONE IS NOT A FREE AGENT. Since the phone
     * directory stopped falling back to one shared extension (PP-12), `endpointFor()`
     * returns null for someone holding no `sip_extension` — and `placeCall()` takes a
     * non-nullable string, so reserving them would kill the listener mid-call. The
     * guard belongs here, in the one place that decides who can be rung, rather than
     * at each of the three ring sites. A phoneless agent simply never becomes a
     * candidate, so the caller waits exactly as they do when nobody is free (RD-5) —
     * a path that was already built and proven.
     *
     * DEPARTMENTS (inbound-audio slice 7). With a department, its free agents are tried
     * first; only when none is free AND the caller may widen is anyone else tried. The
     * department is re-tried first on every call, so it keeps first pick after widening
     * (D3). Whether to widen is the flow's decision (the client's wait, or nobody in the
     * department logged in); the router only obeys it. With no department: today's
     * query, unchanged (D8).
     *
     * @param  array<int, int>  $skipUserIds
     */
    public function reserveFreeAgent(int $tenantId, array $skipUserIds = [], ?int $departmentId = null, bool $mayWiden = false): ?int
    {
        return TenantContext::run($tenantId, function () use ($skipUserIds, $departmentId, $mayWiden): ?int {
            if ($departmentId !== null) {
                $reserved = $this->reserveFirstFree($skipUserIds, $departmentId);

                if ($reserved !== null || ! $mayWiden) {
                    return $reserved;
                }
            }

            return $this->reserveFirstFree($skipUserIds);
        });
    }

    /**
     * Is anyone in this department at work — any status but Offline, with a fresh
     * heartbeat? On break counts as there (D4): they will be back, so the caller waits
     * for them rather than widening at once. A member with no phone does not count,
     * because they can never be rung (SEC-1).
     */
    public function isAnyoneInDepartmentLoggedIn(int $tenantId, int $departmentId): bool
    {
        return TenantContext::run($tenantId, fn (): bool => AgentPresence::query()
            ->where('status', '!=', PresenceStatus::Offline->value)
            ->where('last_seen_at', '>=', $this->freshThreshold())
            ->whereIn('user_id', $this->membersOf($departmentId))
            ->whereIn('user_id', User::query()->whereNotNull('sip_extension')->select('id'))
            ->exists());
    }

    /** Is this agent in this department? Tells the flow whether a reservation widened. */
    public function isInDepartment(int $tenantId, int $departmentId, int $userId): bool
    {
        return TenantContext::run($tenantId, fn (): bool => $this->membersOf($departmentId)->where('user_id', $userId)->exists());
    }

    /**
     * The first-free read and the atomic tag. Runs inside the caller's TenantContext.
     *
     * @param  array<int, int>  $skipUserIds
     */
    private function reserveFirstFree(array $skipUserIds, ?int $departmentId = null): ?int
    {
        $freshThreshold = $this->freshThreshold();

        $candidates = AgentPresence::query()
            ->where('status', PresenceStatus::Ready->value)
            ->where('last_seen_at', '>=', $freshThreshold)
            ->whereIn('user_id', User::query()->whereNotNull('sip_extension')->select('id'))
            ->when($departmentId !== null, fn ($query) => $query->whereIn('user_id', $this->membersOf($departmentId)))
            ->when($skipUserIds !== [], fn ($query) => $query->whereNotIn('user_id', $skipUserIds))
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
    }

    /** The department's member ids, as a subquery. The membership table is walled by RLS. */
    private function membersOf(int $departmentId): Builder
    {
        return DB::table('department_user')->where('department_id', $departmentId)->select('user_id');
    }

    private function freshThreshold(): CarbonInterface
    {
        return now()->subSeconds((int) config('telephony.presence.stale_after_seconds'));
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
