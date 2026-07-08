<?php

namespace App\Models;

use App\Enums\PresenceStatus;
use App\Enums\StintEndedVia;
use App\Tenancy\BelongsToTenant;
use Database\Factories\AgentStatusHistoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One stint on the who's-free board (BK-2): an unbroken stay in one status,
 * opened when the status is set and closed by the NEXT change (or lazily after
 * a dead session, BK-6). The board (`agent_presence`) says "right now"; this
 * table remembers — the PD-6 seam reporting was promised.
 *
 * Append-only by design: the single close is the only update a row ever gets.
 * No activity log on purpose (the AgentPresence posture): these rows ARE the
 * record, written by the console's single door, never edited by a human.
 *
 * Break stints carry the category AND its limit as it stood (BK-2 snapshot) —
 * fairness disputes are about the rule at the time, not the rule today.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $user_id
 * @property PresenceStatus $status
 * @property int|null $break_category_id
 * @property int|null $limit_minutes
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property StintEndedVia|null $ended_via
 */
class AgentStatusHistory extends Model
{
    /** @use HasFactory<AgentStatusHistoryFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * "history" is a mass noun — the table stays singular (BK-2 names it
     * `agent_status_history`), the `agent_presence` precedent.
     */
    protected $table = 'agent_status_history';

    protected $fillable = [
        'user_id',
        'status',
        'break_category_id',
        'limit_minutes',
        'started_at',
        'ended_at',
        'ended_via',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PresenceStatus::class,
            'limit_minutes' => 'integer',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'ended_via' => StintEndedVia::class,
        ];
    }

    /**
     * The agent this stint belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The break type a break stint was taken under (null = untyped break).
     *
     * @return BelongsTo<BreakCategory, $this>
     */
    public function breakCategory(): BelongsTo
    {
        return $this->belongsTo(BreakCategory::class);
    }

    /**
     * Still-running stints (ended_at null) — the board's and the close logic's
     * shared filter.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    /**
     * BK-6's ONE shared arithmetic: when a dead session's stint effectively
     * ended — the last heartbeat plus the stale window. The write-side lazy close
     * (RecordStatusStint) stamps this moment into the row; every duration reader
     * applies the SAME method virtually first (effectiveEndedAt below) — so the
     * physical close can never change a number a reader already showed. Floored
     * at the stint's own start so the close can never predate the open
     * (pathological-clock guard).
     */
    public function staleEndCutoff(?AgentPresence $presence): Carbon
    {
        $staleAfter = (int) config('telephony.presence.stale_after_seconds');

        $cutoff = $presence?->last_seen_at?->copy()->addSeconds($staleAfter);

        if ($cutoff === null || $cutoff->lt($this->started_at)) {
            return $this->started_at->copy();
        }

        return $cutoff;
    }

    /**
     * BK-6 read-side: when this stint effectively ended, given the agent's board
     * row. A closed row answers with its real end. An open row backed by a FRESH
     * heartbeat is genuinely still running — null, the caller counts up to now.
     * An open row whose session died (no board row, or heartbeat gone quiet past
     * the stale window) reads as ended at the stale cutoff — the exact moment the
     * write-side lazy close will eventually stamp, so duration math is identical
     * before and after the physical close.
     */
    public function effectiveEndedAt(?AgentPresence $presence): ?Carbon
    {
        if ($this->ended_at !== null) {
            return $this->ended_at;
        }

        if ($presence !== null && ! $presence->isStale()) {
            return null;
        }

        return $this->staleEndCutoff($presence);
    }
}
