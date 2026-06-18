<?php

namespace App\Models;

use App\Audit\LogsModelActivity;
use App\Enums\CallDirection;
use App\Enums\CallOutcome;
use App\Tenancy\BelongsToTenant;
use Database\Factories\CallFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A call record / CDR (B3). One row per call, written by the web wrap-up (the
 * single writer, D2): the lead update + the `call.wrapped_up` audit ride along as
 * side-effects of this row. The always-on listener never writes one — it only
 * ENRICHES (recording in CP-B3-2; real timing + true outcome trunk-era).
 *
 * Tenant-owned (BelongsToTenant + RLS). All four business FKs are nullable: an
 * ad-hoc typed-number call has no lead/campaign/disposition (D5).
 *
 * STAGING NOTE (S39 — see PRD/phase-2/b3-calls-table.md banner): `outcome` is
 * AGENT-REPORTED and provisional in v1 (derived from the disposition's is_contact;
 * null for dispositionless ad-hoc calls). The trunk-era watcher overrides it with
 * the real line-result via `correlation_id`. The disposition stays the separate
 * business record. `started_at`/`answered_at`/`duration_seconds` are likewise
 * trunk-era; `ended_at` is COARSE (= wrap-up time) in v1.
 *
 * @property int $id
 * @property int $tenant_id
 * @property CallDirection $direction
 * @property string $from_number
 * @property string $to_number
 * @property int|null $lead_id
 * @property int|null $campaign_id
 * @property int|null $agent_id
 * @property int|null $disposition_id
 * @property CallOutcome|null $outcome
 * @property string|null $correlation_id
 * @property Carbon|null $started_at
 * @property Carbon|null $answered_at
 * @property Carbon|null $ended_at
 * @property int|null $duration_seconds
 * @property string|null $recording_disk
 * @property string|null $recording_path
 */
class Call extends Model
{
    /** @use HasFactory<CallFactory> */
    use BelongsToTenant, HasFactory, LogsModelActivity;

    protected $fillable = [
        'direction',
        'from_number',
        'to_number',
        'lead_id',
        'campaign_id',
        'agent_id',
        'disposition_id',
        'outcome',
        'correlation_id',
        'started_at',
        'answered_at',
        'ended_at',
        'duration_seconds',
        'recording_disk',
        'recording_path',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => CallDirection::class,
            'outcome' => CallOutcome::class,
            'started_at' => 'datetime',
            'answered_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_seconds' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * The agent who handled the call (resolved from web auth at wrap-up).
     *
     * @return BelongsTo<User, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /**
     * @return BelongsTo<Disposition, $this>
     */
    public function disposition(): BelongsTo
    {
        return $this->belongsTo(Disposition::class);
    }

    /**
     * @return array<int, string>
     */
    protected function activityLogAttributes(): array
    {
        return [
            'direction', 'from_number', 'to_number', 'lead_id', 'campaign_id',
            'agent_id', 'disposition_id', 'outcome', 'correlation_id', 'recording_path',
        ];
    }

    protected function activityLogName(): string
    {
        return 'call';
    }
}
