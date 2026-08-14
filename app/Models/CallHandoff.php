<?php

namespace App\Models;

use App\Enums\CallEndedBy;
use App\Tenancy\BelongsToTenant;
use Database\Factories\CallHandoffFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One listener->browser handoff note (B2.4b TH-1): the watcher drops a call's ticket
 * (a UUID) here at ring-time, keyed by the agent it is ringing (agent_user_id, TH-2);
 * the agent's screen reads it back and stamps it onto the calls row so the inbound
 * recording attaches by matching ids.
 *
 * It now carries TIMING as well as the ticket (call-timing.md CT-3). The watcher owns
 * every moment on a call and cannot write the calls row itself (D2's single-writer
 * rule), so it stamps them here and the screen copies them onto the row at wrap-up.
 * All four therefore come off one clock and can never disagree:
 *
 *   arrived_at   the caller reached us            (the watcher's own startedAt)
 *   created_at   this agent's phone started ringing — the note is written immediately
 *                before the ring, so the ring moment needs no column of its own
 *   answered_at  this agent picked up
 *   ended_at     THIS AGENT's part ended — not the call's end. On a transfer the first
 *                agent's ends when they are dropped, minutes before the caller hangs
 *                up (CT-12); on a conference, when they leave (CT-12a).
 *
 * Tenant-owned (RLS-walled, like agent_presence). The watcher writes it scoped via
 * TenantContext::run() (it has no logged-in user, TH-5); the screen reads it inside
 * its own request's tenant context, so it only ever sees its own client's notes.
 *
 * Lifecycle: prune-on-write (TH-6) — each new ring wipes that agent's prior note, so
 * at most one row per agent lingers; no scheduler. The read is a plain, non-consuming
 * read (idempotent); the note simply lingers until the agent's next call sweeps it.
 *
 * 🔴 BECAUSE IT LINGERS, EVERY TIMING READ AND WRITE IS MATCHED ON THE TICKET (CT-11),
 * never on the agent alone. An agent who let their phone ring out still holds that
 * caller's arrival time; a read keyed on "my newest note" would stamp an inbound wait
 * onto their next outbound call. Ticket-matching closes that path and every one like
 * it — an outbound call mints a ticket that can match no note at all.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $agent_user_id
 * @property string $ticket
 * @property string|null $dialled_number
 * @property Carbon|null $arrived_at
 * @property Carbon|null $answered_at
 * @property Carbon|null $ended_at
 * @property CallEndedBy|null $ended_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CallHandoff extends Model
{
    /** @use HasFactory<CallHandoffFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'agent_user_id',
        'ticket',
        'dialled_number',
        'arrived_at',
        'answered_at',
        'ended_at',
        'ended_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'arrived_at' => 'datetime',
            'answered_at' => 'datetime',
            'ended_at' => 'datetime',
            'ended_by' => CallEndedBy::class,
        ];
    }

    /**
     * The agent this note is waiting for.
     *
     * @return BelongsTo<User, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_user_id');
    }
}
