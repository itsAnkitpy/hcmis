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
}
