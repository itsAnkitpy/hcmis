<?php

declare(strict_types=1);

namespace App\Telephony;

use App\Audit\Audit;
use App\Enums\LeadStatus;
use App\Enums\TenantStatus;
use App\Models\Campaign;
use App\Models\DncEntry;
use App\Models\Lead;
use App\Models\Tenant;
use App\Telephony\Flows\CallToAgentFlow;
use App\Telephony\Flows\HandlerRegistry;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The dial tick (DIAL-1 DP-7 / A3): the program that takes the caller off the agent's
 * finger. Once a second, for every campaign that is switched on and inside its own
 * calling window, it books a free desk, takes the next lead off the list, checks the
 * number, and rings it.
 *
 * IT RIDES THE LISTENER'S LOOP, it is not a process of its own (DQ-1, as revised S144).
 * The listener is the only thing that hears a dial fail, and a desk has exactly one way
 * back to Ready — a handler calling releaseReservation. A second process would have to
 * buy that back with a table and a reaper. The split trigger is written down in the plan:
 * when predictive arrives, this moves out.
 *
 * RESERVE FIRST, ALWAYS (DQ-3). The desk is booked before the number is dialled, so a
 * customer can never answer into an empty floor. Every path that gives up after booking
 * hands the desk straight back — that is what the three early returns below are for, and
 * a leaked desk is worse than never dialing at all (DP-10).
 *
 * ONE CALL PER CAMPAIGN PER TICK, not one per free desk. VICIdial's progressive is a
 * fixed 1:1 — one call for each waiting agent — and this stays under that ceiling rather
 * than over it, so the abandoned rate stays zero by construction (DQ-6's 3% cap). The
 * difference is only in timing: five free desks fill over about five seconds instead of
 * in one burst, because every call placed is an HTTP round trip on the same thread that
 * is carrying live calls.
 * ponytail: one dial per campaign per tick. If a floor is measurably slow to fill after
 * wrap-up, loop here until reserveFreeAgent returns null — but measure the loop's effect
 * on the heartbeat file first (deploy/telephony-watchdog.sh reads it).
 */
class ProgressiveDialer
{
    public function __construct(
        private readonly TelephonyProvider $telephony,
        private readonly AgentRouter $router,
    ) {}

    /**
     * One pass. The registry is the switchboard, passed in rather than resolved: the
     * listener builds exactly one for the life of the process, and a handler born here
     * must be findable by the same phone-book every other event routes through.
     *
     * 🔴 ONE TRANSACTION PER CAMPAIGN, not one per client. TenantContext::run opens a
     * transaction, and dialOne deliberately lets a lost line escape because that is what
     * drives the listener's reconnect. Sharing one transaction across a client's
     * campaigns means that escape rolls back the campaign BEFORE it too — un-booking a
     * desk and un-claiming a lead for a call already ringing on a real phone. The
     * conditional release can never put that right (it looks for an on_call the rollback
     * already erased), and the freed lead is dialled again on the very next tick, so one
     * customer gets two calls and one desk gets both. A campaign per transaction keeps a
     * rollback to the dial that actually failed.
     */
    public function tick(HandlerRegistry $registry): void
    {
        foreach ($this->dialingTenantIds() as $tenantId) {
            $campaigns = TenantContext::run(
                $tenantId,
                fn () => Campaign::query()->dialable()->get(),
            );

            foreach ($campaigns as $campaign) {
                TenantContext::run($tenantId, fn () => $this->dialOne($campaign, $tenantId, $registry));
            }
        }
    }

    /**
     * Which clients have a campaign switched on at all — one ownerless read across every
     * client (the NumberDirectory posture), because the tick has no client in scope and
     * asking each client in turn would open a transaction per client per second forever.
     *
     * 🔴 The WINDOW is deliberately not asked here. A window is wall-clock time in the
     * client's own zone (F7), and an ownerless read has no client to ask, so it would
     * silently fall back to the system default and dial a Dubai floor on India's clock.
     * The clock question is asked once per client, inside that client's context.
     *
     * Suspended and archived clients are excluded here rather than in the scope: whether
     * a campaign should dial is a question about the campaign, whether a client should be
     * running at all is a question about the client.
     *
     * @return array<int, int>
     */
    private function dialingTenantIds(): array
    {
        return TenantContext::runGlobal(fn (): array => Campaign::query()
            ->switchedOn()
            ->whereIn('tenant_id', Tenant::query()
                ->where('status', TenantStatus::Active->value)
                ->select('id'))
            ->distinct()
            ->pluck('tenant_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());
    }

    /**
     * Book a desk, take the next lead, check the number, ring it. Runs inside the
     * client's context, so every read and write here is walled to them.
     */
    private function dialOne(Campaign $campaign, int $tenantId, HandlerRegistry $registry): void
    {
        $agentUserId = $this->router->reserveFreeAgent($tenantId);

        if ($agentUserId === null) {
            return;   // nobody free: nothing booked, nothing to give back.
        }

        // DP-14: the retry gap is chained HERE and nowhere else. callable() is the
        // agent console's rule too, and the console is meant to be unchanged.
        $lead = Lead::query()
            ->callable($campaign->id)
            ->notDialedRecently($campaign->retry_gap_minutes)
            ->first();

        // claim() is the race the console already lost once (DP-3): an agent pressing Dial
        // in the same second takes the lead from under us. Losing it costs one tick.
        if ($lead === null || ! $lead->claim()) {
            $this->router->releaseReservation($tenantId, $agentUserId);

            return;
        }

        if (DncEntry::blocks($lead->phone)) {
            $this->blockLead($lead);
            $this->router->releaseReservation($tenantId, $agentUserId);

            return;
        }

        try {
            (new CallToAgentFlow($this->telephony, $registry, $this->router))
                ->beginDialedCall($tenantId, $agentUserId, $lead, $campaign);
        } catch (Throwable $exception) {
            // 🔴 The handler holds the booking but owns no leg yet, so nothing downstream
            // can ever release it (the S88 hazard, one step earlier). Give the desk back
            // here or that agent reads "On a call" until somebody edits the database.
            $this->router->releaseReservation($tenantId, $agentUserId);

            Log::error('Progressive dial failed before the call was placed; the desk was handed back.', [
                'tenant' => $tenantId,
                'campaign' => $campaign->id,
                'lead' => $lead->id,
                'agent' => $agentUserId,
                'error' => $exception->getMessage(),
            ]);

            if ($exception instanceof AriConnectionLost) {
                throw $exception;   // everyone's problem — it drives the listener's reconnect.
            }
        }
    }

    /**
     * A served lead whose number is on the client's do-not-call list: never dial it, drop
     * it out of rotation, leave an audit line. The agent console's own hard-stop, moved
     * behind one shared check in A1 — no attempt counted and no disposition written,
     * because no call was placed, and faking either would lie to the campaign reports.
     */
    private function blockLead(Lead $lead): void
    {
        $lead->update(['status' => LeadStatus::Closed]);

        Audit::dncBlocked($lead);
    }
}
