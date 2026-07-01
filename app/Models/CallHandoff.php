<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Database\Factories\CallHandoffFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One listener->browser ticket-handoff note (B2.4b TH-1): the watcher drops a
 * call's ticket (a UUID) here at ring-time, keyed by the agent it is ringing
 * (agent_user_id, TH-2); the agent's screen reads it back and stamps it onto the
 * calls row so the inbound recording attaches by matching ids.
 *
 * Tenant-owned (RLS-walled, like agent_presence). The watcher writes it scoped via
 * TenantContext::run() (it has no logged-in user, TH-5); the screen reads it inside
 * its own request's tenant context, so it only ever sees its own client's notes.
 *
 * Lifecycle: prune-on-write (TH-6) — each new ring wipes that agent's prior note, so
 * at most one row per agent lingers; no scheduler. The read is a plain, non-consuming
 * read (idempotent); the note simply lingers until the agent's next call sweeps it.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $agent_user_id
 * @property string $ticket
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
    ];

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
