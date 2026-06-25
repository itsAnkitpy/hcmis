<?php

namespace App\Models;

use App\Enums\PresenceStatus;
use App\Tenancy\BelongsToTenant;
use Database\Factories\AgentPresenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One agent's spot on the who's-free board (B2.2 PD-1). Tenant-owned, ONE row per
 * agent, OVERWRITTEN in place (PD-6) — a status change or heartbeat updates this
 * row, never appends. No activity log on purpose: the board churns constantly
 * (a heartbeat every ~15s), so time-in-status history is a future reporting seam
 * (PD-6), not auto-logged here.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property PresenceStatus $status
 * @property Carbon|null $last_seen_at
 */
class AgentPresence extends Model
{
    /** @use HasFactory<AgentPresenceFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * "presence" is a mass noun — the table stays singular (PD-6 names it
     * `agent_presence`), so the convention-derived `agent_presences` is overridden.
     */
    protected $table = 'agent_presence';

    protected $fillable = [
        'user_id',
        'status',
        'last_seen_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PresenceStatus::class,
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * The agent this board row belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The board's TRUTH at read time (PD-4): a row whose heartbeat has gone stale
     * reads as Offline even if its stored status still says Ready — a tab that
     * crashed must not leave a lying "Ready" the router would ring. The stored
     * status is returned only while the heartbeat is fresh.
     */
    public function effectiveStatus(): PresenceStatus
    {
        if ($this->status === PresenceStatus::Offline || $this->isStale()) {
            return PresenceStatus::Offline;
        }

        return $this->status;
    }

    /**
     * Whether the heartbeat has lapsed (PD-4): no stamp, or older than the stale
     * window. The window is config-driven (telephony.presence.stale_after_seconds).
     */
    public function isStale(): bool
    {
        if ($this->last_seen_at === null) {
            return true;
        }

        $staleAfter = (int) config('telephony.presence.stale_after_seconds');

        return $this->last_seen_at->lt(now()->subSeconds($staleAfter));
    }
}
