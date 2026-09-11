<?php

declare(strict_types=1);

namespace App\Telephony;

use App\Enums\PresenceStatus;
use App\Enums\TenantStatus;
use App\Models\AgentPresence;
use App\Models\Tenant;
use App\Models\User;
use App\Telephony\Flows\Switchboard;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;

/**
 * The missing reaper (DIAL-1 R8, closing the gap DQ-1 named): put back a desk that is
 * tagged "On a call" with no call behind it.
 *
 * A desk is booked by writing PresenceStatus::OnCall to the database (AgentRouter::
 * reserveFreeAgent) and handed back by a live handler calling releaseReservation. Kill
 * the listener between those two — a deploy, a systemd restart, a crash the watchdog
 * restarts — and the handler dies while the tag lives on. Nothing else ever clears it:
 * the stale-heartbeat net frees an agent whose browser stopped stamping, and this agent
 * is sitting at their desk stamping every fifteen seconds. They are simply gone from the
 * floor until somebody edits the database.
 *
 * This is not a dialer bug — an inbound call ringing at the wrong moment leaks a desk the
 * same way. The dialer is what turns it from a rarity into one per campaign per second,
 * which is why it is being fixed now.
 *
 * 🔴 TWO SIGNALS, AND THE SECOND IS NOT OPTIONAL. "No handler holds this desk" is not
 * enough on its own: when the ARI connection drops, Asterisk does NOT hang up the
 * channels sitting in the application — it deactivates the app and reactivates it on
 * reconnect — so a restarted listener routinely holds no handler for an agent who is
 * genuinely mid-conversation. Freeing on the first signal alone would hand a talking
 * agent straight back to the dialer, which is a worse bug than the one being fixed. So
 * the voice box is asked whether that agent's phone is actually in a channel, and only a
 * desk that fails BOTH tests is put back.
 *
 * The voice box is asked only when a candidate exists, which on a healthy box is never —
 * every On a call tag is matched by a live handler, the query answers "no candidates",
 * and no HTTP is made at all.
 */
class ReservationReaper
{
    public function __construct(
        private readonly TelephonyProvider $telephony,
    ) {}

    /**
     * One pass. The switchboard is passed in rather than resolved for the reason the
     * dialer takes it that way: the listener builds exactly one for the life of the
     * process, and its in-memory phone-book IS the "is there a live call" answer.
     */
    public function tick(Switchboard $switchboard): void
    {
        $held = $switchboard->heldDesksByTenant();
        $candidates = $this->unbackedDesks($held);

        if ($candidates === []) {
            return;
        }

        // One question to the voice box for the whole pass, asked only now that something
        // looks wrong. A channel name is its endpoint plus a dash and Asterisk's own
        // suffix ('PJSIP/1101-00000003'), which is how a name is built and has been for
        // as long as PJSIP has existed — the same assumption endpointFor already makes
        // from the other side.
        $liveChannels = $this->telephony->liveChannelNames();

        foreach ($candidates as $tenantId => $agentUserIds) {
            foreach ($agentUserIds as $agentUserId) {
                $endpoint = $this->endpointFor($tenantId, $agentUserId);

                $onAChannel = $endpoint !== null && array_any(
                    $liveChannels,
                    fn (string $name): bool => str_starts_with($name, $endpoint.'-'),
                );

                if ($onAChannel) {
                    continue;   // genuinely busy, handler or no handler.
                }

                $this->handBack($tenantId, $agentUserId);
            }
        }
    }

    /**
     * Desks tagged On a call that no live call is holding, by client. One ownerless read
     * across every client (the dialer's posture, and for the same reason: this runs on a
     * loop with no client in scope, and asking each client in turn would open a
     * transaction per client per pass forever).
     *
     * Suspended and archived clients are left alone entirely — nothing of theirs should
     * be running, and quietly re-Ready-ing their agents would be this code inventing a
     * decision that belongs to whoever suspended them.
     *
     * @param  array<int, array<int, int>>  $held
     * @return array<int, array<int, int>>
     */
    private function unbackedDesks(array $held): array
    {
        $rows = TenantContext::runGlobal(fn () => AgentPresence::query()
            ->where('status', PresenceStatus::OnCall->value)
            ->whereIn('tenant_id', Tenant::query()
                ->where('status', TenantStatus::Active->value)
                ->select('id'))
            ->get(['tenant_id', 'user_id']));

        $candidates = [];

        foreach ($rows as $row) {
            $tenantId = (int) $row->tenant_id;
            $agentUserId = (int) $row->user_id;

            if (in_array($agentUserId, $held[$tenantId] ?? [], true)) {
                continue;
            }

            $candidates[$tenantId][] = $agentUserId;
        }

        return $candidates;
    }

    /** This agent's phone, read inside their own client's wall. */
    private function endpointFor(int $tenantId, int $agentUserId): ?string
    {
        $extension = TenantContext::run(
            $tenantId,
            fn (): mixed => User::query()->whereKey($agentUserId)->value('sip_extension'),
        );

        return is_string($extension) ? 'PJSIP/'.$extension : null;
    }

    /**
     * Put one desk back. Conditional on the tag still being the On a call we are
     * reaping — the same guard releaseReservation uses, and for the same reason: the
     * agent's own screen is the authority on Break and Wrap-up, and this must never
     * overwrite one they set between the read above and this write.
     */
    private function handBack(int $tenantId, int $agentUserId): void
    {
        $freed = TenantContext::run($tenantId, fn (): int => AgentPresence::query()
            ->where('user_id', $agentUserId)
            ->where('status', PresenceStatus::OnCall->value)
            ->update(['status' => PresenceStatus::Ready->value]));

        if ($freed === 1) {
            Log::warning('Freed a desk tagged On a call with no call behind it — most likely a listener restart mid-ring.', [
                'tenant' => $tenantId,
                'agentUser' => $agentUserId,
            ]);
        }
    }
}
