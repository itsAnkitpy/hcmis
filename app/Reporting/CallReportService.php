<?php

declare(strict_types=1);

namespace App\Reporting;

use App\Enums\CallDirection;
use App\Models\Call;
use App\Models\User;
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
 * v1 is COUNT-BASED (RP-2): calls, direction split, contacts, sales, no-answer,
 * recording coverage — every column maps to a populated v1 field (see the honesty
 * table in reporting.md). Talk time / occupancy / true connect rate are deferred
 * (NULL timing, no presence history); they light up additively when the real line
 * + a presence log land, without changing this service's signature.
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
     * Report 1 (RP-5) — one row per agent with their call counts + outcome mix.
     * Sorted by busiest agent first. contact_rate is reliable (from `is_contact`);
     * no_answer is the one provisional column (agent-reported `outcome`).
     *
     * @return array<int, array{agent_id: int|null, agent: string, total: int, inbound: int, outbound: int, contacts: int, sales: int, no_answer: int, with_recording: int, contact_rate: float}>
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
            ->groupBy('calls.agent_id')
            ->toBase()
            ->get();

        $names = $this->agentNames($rows->pluck('agent_id')->all());

        return $rows
            ->map(function (object $row) use ($names): array {
                $agentId = $row->agent_id === null ? null : (int) $row->agent_id;
                $total = (int) $row->total;
                $contacts = (int) $row->contacts;

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
     * @return array<int, array{date: string, total: int, inbound: int, outbound: int, contacts: int, sales: int}>
     */
    public function callsByDay(CallReportFilters $filters): array
    {
        return $this->baseQuery($filters)
            ->selectRaw('CAST(calls.created_at AS date) as day')
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
     * @return array<int, array{label: string, count: int, percentage: float}>
     */
    public function dispositionBreakdown(CallReportFilters $filters): array
    {
        $grandTotal = (int) $this->baseQuery($filters)->count();

        return $this->baseQuery($filters)
            ->whereNotNull('calls.disposition_id')
            ->selectRaw('dispositions.label as label')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('dispositions.id', 'dispositions.label')
            ->orderByDesc('count')
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
