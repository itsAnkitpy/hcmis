<?php

namespace App\Models;

use App\Audit\LogsModelActivity;
use App\Enums\CallDirection;
use App\Enums\CallEndedBy;
use App\Enums\CallOutcome;
use App\Tenancy\BelongsToTenant;
use Database\Factories\CallFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A call record / CDR (B3). One row per call, normally written by the web wrap-up
 * (the single writer, D2): the lead update + the `call.wrapped_up` audit ride along
 * as side-effects of this row. The always-on listener mostly ENRICHES (recording in
 * CP-B3-2; real timing + true outcome trunk-era).
 *
 * The one row the listener CREATES is D2's own named exception, in use since
 * B2.3b-i: a caller who was never put through to anybody — they gave up while
 * holding, or we stopped waiting on their behalf. Nobody wrapped that call up, so
 * nothing else would ever record it; those rows carry no agent, lead, campaign or
 * disposition and are what the missed-call list reads.
 *
 * Tenant-owned (BelongsToTenant + RLS). All four business FKs are nullable: an
 * ad-hoc typed-number call has no lead/campaign/disposition (D5).
 *
 * STAGING NOTE (S39 — see PRD/phase-2/b3-calls-table.md banner): `outcome` is
 * AGENT-REPORTED and provisional in v1 (derived from the disposition's is_contact;
 * null for dispositionless ad-hoc calls). The trunk-era watcher overrides it with
 * the real line-result via `correlation_id`. The disposition stays the separate
 * business record.
 *
 * TIMING — the five moments (call-timing.md CT-1/CT-2). Every one is a POINT IN TIME;
 * no duration is ever stored, because a stored length only answers the question it was
 * built for and two moments can be re-cut for ever:
 *
 *   started_at   the caller reached us       ("waited" = started_at -> answered_at)
 *   ringing_at   the far end started ringing ("rang"   = ringing_at -> answered_at)
 *   answered_at  the far end picked up       ("talked" = answered_at -> ended_at)
 *   ended_at     THIS AGENT's part ended     ("wrap-up"= ended_at -> created_at)
 *   created_at   the agent clicked Done
 *
 * "The far end" because the middle three read the same way in both directions: inbound
 * it is the agent's phone ringing and the agent picking up, outbound it is the
 * customer's (CT-16). `started_at` is the one that is inbound-only — an outbound call
 * has no arrival because nobody waited, we placed it (CT-8), so its "waited" is blank.
 *
 * All four are stamped by the listener onto the handoff note and copied here by the
 * wrap-up (CT-3), so they share one clock. Any of them may be null — a missing moment
 * degrades to a blank and is NEVER faked (CT-6): a dash on one row is honest, a
 * plausible wrong number is not.
 *
 * ⤳ `ended_at` used to be the Done click and is now the real hang-up (CT-6, superseding
 * b3-calls-table.md D4). `duration_seconds` is RETIRED (CT-4): it was labelled "Waited
 * for" on one screen and "Duration" on another, so nothing writes it any more and both
 * figures are computed from the moments above. Left in place, unwritten, pending a
 * decision to drop it (CT-14).
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
 * @property Carbon|null $ringing_at
 * @property Carbon|null $answered_at
 * @property Carbon|null $ended_at
 * @property CallEndedBy|null $ended_by
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
        'ringing_at',
        'answered_at',
        'ended_at',
        'ended_by',
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
            'ringing_at' => 'datetime',
            'answered_at' => 'datetime',
            'ended_at' => 'datetime',
            'ended_by' => CallEndedBy::class,
            'duration_seconds' => 'integer',
        ];
    }

    /**
     * How long this customer waited before somebody picked up — or, if nobody ever did,
     * before they were lost (CT-4/CT-7). One expression covers both because the wait
     * ends the same way in each: the moment they stopped waiting.
     *
     * Computed, never stored (CT-1). The retired `duration_seconds` was written for the
     * missed-call case only and labelled "Waited for" there and "Duration" on the Calls
     * list — the same number meaning two different things on two screens.
     */
    public function waitedSeconds(): ?int
    {
        $stoppedWaiting = $this->answered_at ?? $this->ended_at;

        return $this->started_at !== null && $stoppedWaiting !== null
            ? (int) $this->started_at->diffInSeconds($stoppedWaiting)
            : null;
    }

    /**
     * How long the agent and the customer were actually talking (CT-4/CT-6).
     *
     * Pickup to hang-up — NOT to the Done click, which is the row's own created_at and
     * includes however long the agent spent typing notes. On a transferred call the
     * hang-up stored here is when THIS agent's part ended, so each agent's row carries
     * their own conversation rather than the whole call (CT-12).
     */
    public function talkedSeconds(): ?int
    {
        return $this->answered_at !== null && $this->ended_at !== null
            ? (int) $this->answered_at->diffInSeconds($this->ended_at)
            : null;
    }

    /**
     * A span of seconds as a clock, or a dash when we do not have it.
     *
     * Hours appear only when there are some, so an ordinary row stays short. That
     * matters more than it looks: `i:s` alone renders an hour-long wait as `00:00`,
     * which reads as somebody who hung up instantly — the opposite of what happened.
     * A client can set their maximum hold as high as an hour, so it is reachable.
     */
    public static function asClock(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }

        return gmdate($seconds >= 3600 ? 'H:i:s' : 'i:s', $seconds);
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
