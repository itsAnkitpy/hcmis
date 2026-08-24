<?php

namespace App\Models;

use App\Audit\LogsModelActivity;
use App\Enums\CallDirection;
use App\Enums\CallEndedBy;
use App\Enums\CallOutcome;
use App\Support\PhoneNumber;
use App\Tenancy\BelongsToTenant;
use Database\Factories\CallFactory;
use Illuminate\Database\Eloquent\Builder;
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
 * @property int|null $hold_seconds
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
        // hold.md H-1/H-7: how long this caller spent on hold, as one total. The single
        // deliberate exception to CT-1 — a call held three times has no pair of moments.
        'hold_seconds',
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
            'hold_seconds' => 'integer',
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
     *
     * 🔴 MINUS THE HELD TIME (hold.md H-3). A five-minute call with three minutes of
     * music in it is two minutes of conversation, not five. Both vendors we checked do
     * the same, and this is the whole reason Hold ships before the Agent Productivity
     * Report — the report's Average Handle Time is talk + hold + wrap, and a talk figure
     * that already contains the hold counts it twice.
     *
     * One guard in the one shared function all three readers call (the Calls list, the
     * detail page and the export), so the number cannot mean two things on two screens.
     * A call that was never held subtracts nothing and reads exactly as it does today.
     *
     * Never negative: a hold left open by a torn-down call is closed at teardown against
     * the same clock, but a clock that steps backwards must not turn a real conversation
     * into a negative one.
     */
    public function talkedSeconds(): ?int
    {
        if ($this->answered_at === null || $this->ended_at === null) {
            return null;
        }

        return max(0, (int) $this->answered_at->diffInSeconds($this->ended_at) - (int) $this->hold_seconds);
    }

    /**
     * How long the agent spent typing notes after the caller hung up (CT-6) — the
     * hang-up to the Done click, which is this row's own created_at.
     *
     * Moved here from CallExportRows' private method (apr.md AP-3) so the export and
     * the Agent Productivity Report can never print two different wrap-up numbers.
     * The Average Handle Time is talk + hold + wrap, so a second copy of this rule
     * would be a second answer to one question — the failure that retired
     * `duration_seconds` (CT-4).
     *
     * BLANK, NOT ZERO, WHEN NOBODY ANSWERED (call-export.md CE-4). A call nobody
     * picked up has no agent and no Done click: the listener writes the row at the
     * moment it gives up, so both moments are the same instant and the span comes out
     * as a truthful-looking 0. Zero says "the agent wrapped up instantly"; blank says
     * "there was no agent", which is what happened.
     */
    public function wrappedSeconds(): ?int
    {
        if ($this->answered_at === null || $this->ended_at === null || $this->created_at === null) {
            return null;
        }

        return (int) $this->ended_at->diffInSeconds($this->created_at);
    }

    /**
     * A span of seconds as a clock, or a dash when we do not have it.
     *
     * Hours appear only when there are some, so an ordinary row stays short. That
     * matters more than it looks: `i:s` alone renders an hour-long wait as `00:00`,
     * which reads as somebody who hung up instantly — the opposite of what happened.
     * A client can set their maximum hold as high as an hour, so it is reachable.
     *
     * 🔴 THE HOURS ARE BUILT BY HAND, NOT BY gmdate('H:i:s'), WHICH WRAPS AT 24 (S117).
     * Every caller used to pass a single call span, which cannot reach a day. The Agent
     * Productivity Report passes a SHIFT TOTAL across a date range, and a 40-hour week
     * printed as `16:00:00` while the same figure read `144000` in its own CSV and
     * `40h 0m` on Agent Detail. Below 24 hours this returns exactly what gmdate did.
     */
    public static function asClock(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }

        return $seconds >= 3600
            ? sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60)
            : gmdate('i:s', $seconds);
    }

    /**
     * CH-1 — every call to or from one customer's number, newest first. The ONE
     * definition of "this number's history", shared by the agent console's ring-time
     * panel (CH-2) and the Call Review number filter (CH-3), so the two screens can
     * never disagree about what a number's history is.
     *
     * Keyed on the NUMBER, not on `lead_id`, because the number is the only key that
     * works on all four call paths — matched inbound, matched outbound, unmatched
     * inbound and ad-hoc manual dial. A call that HAS a lead carries the number too,
     * so the number alone loses nothing, and an unknown caller finally has a history
     * without anybody having to create a customer record for them first.
     *
     * 🔴 The direction is paired with the column deliberately. On an outbound call
     * `from_number` is OUR OWN caller-ID and on an inbound call `to_number` is OUR OWN
     * line, so a plain `to_number = ? OR from_number = ?` would read a client's own
     * calls back as that number's customer history the moment one of our own numbers
     * is dialled.
     *
     * Normalized here rather than at each call site, for the same reason CH-5 fixes
     * the listener at its single write: one place to get right. A number that
     * normalizes to nothing — an anonymous caller has none at all — matches no rows
     * rather than every row.
     *
     * Tenant-walled by RLS + BelongsToTenant like every other read. 🔴 Worth naming:
     * this query starts from a phone number, which is not a tenant-scoped value on its
     * own, so the wall is doing all the work here alone (proved by CH-T1).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForCustomerNumber(Builder $query, ?string $phone): Builder
    {
        $phone = PhoneNumber::normalize($phone);

        if ($phone === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where(function (Builder $q) use ($phone): void {
                $q->where(fn (Builder $outbound): Builder => $outbound
                    ->where('direction', CallDirection::Outbound)
                    ->where('to_number', $phone))
                    ->orWhere(fn (Builder $inbound): Builder => $inbound
                        ->where('direction', CallDirection::Inbound)
                        ->where('from_number', $phone));
            })
            ->latest('created_at');
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
