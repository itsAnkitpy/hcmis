<?php

declare(strict_types=1);

namespace App\Reporting;

use App\Enums\CallDirection;
use App\Models\Call;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The counting layer (RP-1): the ONE place that turns raw `calls` rows into grouped
 * totals. Both the report tables (Slice 1) and the dashboard widgets (Slice 2) read
 * it, so a chart can never drift from its table — they compute from the same maths.
 *
 * Everything runs inside the CURRENT tenant context (the caller sets it — the web
 * request via SetCurrentTenant, a test via TenantContext::run). So the per-client
 * wall is inherited for free (RP-4): the Eloquent TenantScope + Postgres RLS filter
 * every query to the active client — a per-client Team Leader sees only their own
 * numbers, with no extra where-clause here. (Global HC staff run cross-tenant, the
 * same posture as Call Review, so their totals roll up every client they can see.)
 *
 * 🔴 RP-2 IS OUT OF DATE AND apr.md AP-10 CORRECTS IT. This class used to say talk
 * time, occupancy and a true connect rate were deferred for want of real timing and a
 * presence log. Both of RP-2's own reopen triggers have since fired: the real line
 * landed and stamps the timing moments (call-timing.md, S103), and the presence-event
 * log exists (`agent_status_history`, S74). agentProductivity() therefore returns talk,
 * hold, wrap and Average Handle Time as of the APR build. What is still true from RP-2:
 * the connect rate remains provisional (the outcome is agent-reported), and there is no
 * shift history at all before S74 — those days read blank, never zero.
 *
 * WHY the LEFT JOIN to dispositions: contacts / sales / disposition labels live on
 * `dispositions`, not `calls`. One join reads them in a single grouped query; an
 * ad-hoc dispositionless call left-joins to NULL and is correctly counted as neither
 * a contact nor a sale. Both tables are tenant-walled, so the join stays within the
 * client. Columns are qualified `calls.*` throughout because both tables carry
 * tenant_id / campaign_id / created_at (avoids an ambiguous-column error).
 */
class CallReportService
{
    /**
     * A call this agent actually handled (apr.md AP-6): somebody picked it up and it
     * ended. It is the condition behind all three time sums AND the divisor of the
     * Average Handle Time, named once so the four can never be told different things.
     *
     * 🔴 THE AHT DIVISOR IS ANSWERED CALLS, NOT THE TOTAL COLUMN. A call nobody
     * answered has no talk, no hold and a blank wrap; counting it on the bottom would
     * drag every average down for an agent who caught a lot of no-answers. Amazon
     * Connect and Five9 both average over interactions handled.
     */
    private const HANDLED = 'calls.answered_at IS NOT NULL AND calls.ended_at IS NOT NULL';

    /**
     * Talk time, in the database (AP-6) — pickup to hang-up MINUS the held total, the
     * exact rule Call::talkedSeconds() applies in PHP, floored at zero the same way.
     *
     * 🔴 The COALESCE is not decoration: talkedSeconds() casts a null hold to 0, and a
     * database that did not would return NULL for every call recorded before Hold
     * shipped, poisoning the whole SUM. The TRUNC matches PHP's (int) cast so the two
     * engines round identically; the ::bigint keeps the sum an integer rather than a
     * float that prints in scientific notation.
     */
    private const TALK_SECONDS = 'GREATEST(0, TRUNC(EXTRACT(EPOCH FROM (calls.ended_at - calls.answered_at)))::bigint - COALESCE(calls.hold_seconds, 0))';

    /** Held time (hold.md H-1): one stored total per call, a null reading as none. */
    private const HELD_SECONDS = 'COALESCE(calls.hold_seconds, 0)';

    /** Wrap-up (CT-6, AP-6) — hang-up to the Done click, Call::wrappedSeconds() in SQL. */
    private const WRAP_SECONDS = 'TRUNC(EXTRACT(EPOCH FROM (calls.created_at - calls.ended_at)))::bigint';

    /**
     * Report 1 (RP-5, extended by apr.md AP-6) — one row per agent with their call
     * counts, outcome mix and handling time for the chosen dates. Sorted by busiest
     * agent first. contact_rate is reliable (from `is_contact`); no_answer is the one
     * provisional column (agent-reported `outcome`).
     *
     * 🔴 THE TIME SUMS RUN IN THE DATABASE, NOT IN PHP. They are expressions on the
     * one grouped query that was already being run, so a month costs one read instead
     * of a hundred thousand rows pulled into memory to produce three numbers. That
     * makes the SQL above the ONE accepted second copy of an arithmetic rule in this
     * build (AP-3), and CallReportServiceTest welds it to the PHP accessors with a
     * test that fails the moment either side is edited alone.
     *
     * `aht_seconds` is null, never 0, when the agent answered nothing in range — zero
     * would claim an instant handle time for somebody who handled no calls (CE-4).
     *
     * @return array<int, array{agent_id: int|null, agent: string, total: int, inbound: int, outbound: int, contacts: int, sales: int, no_answer: int, with_recording: int, contact_rate: float, answered: int, talk_seconds: int, hold_seconds: int, wrap_seconds: int, aht_seconds: int|null}>
     */
    public function agentProductivity(CallReportFilters $filters): array
    {
        $rows = $this->baseQuery($filters)
            ->selectRaw('calls.agent_id as agent_id')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("COUNT(*) FILTER (WHERE calls.direction = 'inbound') as inbound")
            ->selectRaw("COUNT(*) FILTER (WHERE calls.direction = 'outbound') as outbound")
            ->selectRaw('COUNT(*) FILTER (WHERE dispositions.is_contact) as contacts')
            ->selectRaw('COUNT(*) FILTER (WHERE dispositions.is_sale) as sales')
            ->selectRaw("COUNT(*) FILTER (WHERE calls.outcome = 'no_answer') as no_answer")
            ->selectRaw('COUNT(*) FILTER (WHERE calls.recording_path IS NOT NULL) as with_recording')
            ->selectRaw('COUNT(*) FILTER (WHERE '.self::HANDLED.') as answered')
            ->selectRaw('COALESCE(SUM('.self::TALK_SECONDS.') FILTER (WHERE '.self::HANDLED.'), 0) as talk_seconds')
            ->selectRaw('COALESCE(SUM('.self::HELD_SECONDS.') FILTER (WHERE '.self::HANDLED.'), 0) as hold_seconds')
            ->selectRaw('COALESCE(SUM('.self::WRAP_SECONDS.') FILTER (WHERE '.self::HANDLED.'), 0) as wrap_seconds')
            ->groupBy('calls.agent_id')
            ->toBase()
            ->get();

        $names = $this->agentNames($rows->pluck('agent_id')->all());

        return $rows
            ->map(function (object $row) use ($names): array {
                $agentId = $row->agent_id === null ? null : (int) $row->agent_id;
                $total = (int) $row->total;
                $contacts = (int) $row->contacts;
                $answered = (int) $row->answered;
                $talk = (int) $row->talk_seconds;
                $hold = (int) $row->hold_seconds;
                $wrap = (int) $row->wrap_seconds;

                return [
                    'agent_id' => $agentId,
                    'agent' => $agentId === null ? 'Unassigned' : ($names[$agentId] ?? 'Unknown'),
                    'total' => $total,
                    'inbound' => (int) $row->inbound,
                    'outbound' => (int) $row->outbound,
                    'contacts' => $contacts,
                    'sales' => (int) $row->sales,
                    'no_answer' => (int) $row->no_answer,
                    'with_recording' => (int) $row->with_recording,
                    'contact_rate' => $this->rate($contacts, $total),
                    'answered' => $answered,
                    'talk_seconds' => $talk,
                    'hold_seconds' => $hold,
                    'wrap_seconds' => $wrap,
                    'aht_seconds' => $answered > 0 ? (int) round(($talk + $hold + $wrap) / $answered) : null,
                ];
            })
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    /**
     * Report 2, view A (RP-5) — the call mix per calendar day, oldest first. Buckets
     * on `created_at::date` (the indexed column; = the coarse wrap-up instant in v1).
     *
     * 🔴 THE BUCKET IS THE CLIENT'S CALENDAR DAY, NOT OURS (S118, CE-10a). `created_at`
     * is a plain timestamp holding UTC, so it is read as UTC and then moved into the
     * client's zone before the date is taken. Bucketing on the raw UTC date while the
     * RANGE is cut in India time splits one Indian day across two bars, and the first
     * and last bars of every chart read half-empty.
     *
     * @return array<int, array{date: string, total: int, inbound: int, outbound: int, contacts: int, sales: int}>
     */
    public function callsByDay(CallReportFilters $filters): array
    {
        return $this->baseQuery($filters)
            ->selectRaw(
                "CAST(calls.created_at AT TIME ZONE 'UTC' AT TIME ZONE ? AS date) as day",
                [TenantContext::reportTimezone()],
            )
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("COUNT(*) FILTER (WHERE calls.direction = 'inbound') as inbound")
            ->selectRaw("COUNT(*) FILTER (WHERE calls.direction = 'outbound') as outbound")
            ->selectRaw('COUNT(*) FILTER (WHERE dispositions.is_contact) as contacts')
            ->selectRaw('COUNT(*) FILTER (WHERE dispositions.is_sale) as sales')
            ->groupBy('day')
            ->orderBy('day')
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'date' => (string) $row->day,
                'total' => (int) $row->total,
                'inbound' => (int) $row->inbound,
                'outbound' => (int) $row->outbound,
                'contacts' => (int) $row->contacts,
                'sales' => (int) $row->sales,
            ])
            ->all();
    }

    /**
     * Report 2, view B (RP-5) — the disposition breakdown, most-used first. Only
     * dispositioned calls appear (ad-hoc calls have none); percentage is of ALL
     * calls in range (the same grand total the other reports show), so the rows plus
     * an implicit "no disposition" remainder account for every call.
     *
     * Grouped by LABEL, not by disposition id. Dispositions are per-campaign, so
     * "No answer" exists once per campaign as its own row; grouping by id produced
     * one slice per campaign, every one of them printed with the same name. The
     * dashboard donut showed "No answer" twice in its legend and the true share of
     * the outcome — the only number a manager is actually reading — appeared nowhere
     * (S91). Narrowing to a single campaign still counts only that campaign's calls;
     * the merge widens the reading, never the rows counted.
     *
     * @return array<int, array{label: string, count: int, percentage: float}>
     */
    public function dispositionBreakdown(CallReportFilters $filters): array
    {
        $grandTotal = (int) $this->baseQuery($filters)->count();

        return $this->baseQuery($filters)
            ->whereNotNull('calls.disposition_id')
            ->selectRaw('dispositions.label as label')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('dispositions.label')
            // Label breaks count ties so equal-sized outcomes keep a stable order:
            // DispositionMixChart folds the tail of this list into "Other", and an
            // unstable tail would reshuffle the donut between two identical reads.
            ->orderByDesc('count')
            ->orderBy('dispositions.label')
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'label' => (string) $row->label,
                'count' => (int) $row->count,
                'percentage' => $this->rate((int) $row->count, $grandTotal),
            ])
            ->all();
    }

    /**
     * The period totals for the dashboard stat tiles + the direction-split chart
     * (RP-6). recording_coverage is the share of calls in range with a playable
     * recording (now includes inbound, since S60).
     *
     * @return array{total: int, inbound: int, outbound: int, contacts: int, sales: int, with_recording: int, recording_coverage: float}
     */
    public function totals(CallReportFilters $filters): array
    {
        $row = $this->baseQuery($filters)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("COUNT(*) FILTER (WHERE calls.direction = 'inbound') as inbound")
            ->selectRaw("COUNT(*) FILTER (WHERE calls.direction = 'outbound') as outbound")
            ->selectRaw('COUNT(*) FILTER (WHERE dispositions.is_contact) as contacts')
            ->selectRaw('COUNT(*) FILTER (WHERE dispositions.is_sale) as sales')
            ->selectRaw('COUNT(*) FILTER (WHERE calls.recording_path IS NOT NULL) as with_recording')
            ->toBase()
            ->first();

        $total = (int) $row->total;
        $withRecording = (int) $row->with_recording;

        return [
            'total' => $total,
            'inbound' => (int) $row->inbound,
            'outbound' => (int) $row->outbound,
            'contacts' => (int) $row->contacts,
            'sales' => (int) $row->sales,
            'with_recording' => $withRecording,
            'recording_coverage' => $this->rate($withRecording, $total),
        ];
    }

    /**
     * The shared, tenant-walled base query: `calls` LEFT JOINed to `dispositions`,
     * narrowed by the (already guard-parsed) filters. Every consumer starts here, so
     * the date range + agent / campaign / direction narrowing is defined once. The
     * TenantScope global scope adds `calls.tenant_id = <current>` (or nothing under
     * the audited cross-tenant posture), so the wall is automatic.
     */
    private function baseQuery(CallReportFilters $filters): Builder
    {
        return Call::query()
            ->leftJoin('dispositions', 'calls.disposition_id', '=', 'dispositions.id')
            ->when($filters->from, fn (Builder $query, Carbon $from) => $query->where('calls.created_at', '>=', $from))
            ->when($filters->to, fn (Builder $query, Carbon $to) => $query->where('calls.created_at', '<=', $to))
            ->when($filters->agentId, fn (Builder $query, int $id) => $query->where('calls.agent_id', $id))
            ->when($filters->campaignId, fn (Builder $query, int $id) => $query->where('calls.campaign_id', $id))
            ->when($filters->direction, fn (Builder $query, CallDirection $direction) => $query->where('calls.direction', $direction->value))
            // Global-staff client narrowing (RP-4): scopes the cross-tenant rollup to
            // one client. Layers ON TOP of the TenantScope/RLS wall, never around it —
            // a per-client user stays pinned to their own client no matter this value.
            ->when($filters->clientId, fn (Builder $query, int $id) => $query->where('calls.tenant_id', $id));
    }

    /**
     * Resolve agent display names for a set of agent ids. Users are NOT tenant-scoped
     * (a user may belong to several clients via the pivot), so a plain whereIn reads
     * the names — the calls they were counted from were already tenant-walled above.
     *
     * @param  array<int, int|string|null>  $agentIds
     * @return array<int, string>
     */
    private function agentNames(array $agentIds): array
    {
        $ids = array_values(array_filter($agentIds, fn ($id): bool => $id !== null));

        if ($ids === []) {
            return [];
        }

        return User::query()
            ->whereIn('id', $ids)
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * A part-of-whole percentage rounded to one decimal, guarding divide-by-zero
     * (an empty range reads 0.0, never an error).
     */
    private function rate(int $part, int $whole): float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : 0.0;
    }
}
