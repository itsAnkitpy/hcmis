<?php

declare(strict_types=1);

namespace App\Reporting;

use App\Models\AgentPresence;
use App\Models\AgentStatusHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The shift split (apr.md AP-3/AP-4): how a set of agents spent a date range, split
 * across the four statuses that open a stint.
 *
 * ONE PIECE OF ARITHMETIC, TWO READERS. Agent Detail's Time Sheet asks for one agent
 * over one day; the Agent Productivity Report asks for every agent over a range. Both
 * call this, so a manager's report row and that agent's own page can never disagree.
 * The dead-session rule stays where it already lives, on the stint model
 * (AgentStatusHistory::effectiveEndedAt) — it is not copied here.
 *
 * TWO QUERIES, WHATEVER THE AGENT COUNT (AP-4). One read of the stint table for the
 * whole set and one of the board, keyed by agent. A per-agent read would be a hundred
 * queries for one screen on a fifty-agent floor.
 *
 * IT HANDS BACK THE STINTS IT COUNTED (AP-4). The report uses the seconds and ignores
 * the rest; Agent Detail draws its timeline from the very same rows, so the total at
 * the top of its Time Sheet can never disagree with the rows below it. Reading the
 * stints a second time would not be safe: on a live page showing today, an open stint
 * can close between the two reads and the sum stops matching the rows. The break
 * category is deliberately NOT eager-loaded — only Agent Detail renders break names,
 * and it loads them itself on the stints it gets back.
 *
 * Tenant-walled for free: both tables carry BelongsToTenant + RLS, so every query here
 * is already narrowed to the caller's client.
 */
final class ShiftSplit
{
    /**
     * Seconds per status per agent over the range, plus the trimmed stints behind them.
     *
     * Every requested agent gets a row, so an agent who never logged in reads zeros
     * rather than being missing. `active` is the logged-in total: Offline opens no
     * stint (RecordStatusStint returns early for it), so the four tracked statuses
     * always add up to it.
     *
     * `clientId` is the global-staff narrowing (RP-4), and it is optional because only
     * a cross-tenant reader can ever set it — a per-client caller is pinned by RLS and
     * passes nothing. An agent may belong to SEVERAL clients through the pivot, so
     * without it a rollup narrowed to one client still sums that agent's stints from
     * the others into their logged-in total (S117).
     *
     * @param  array<int, int|null>  $agentIds
     * @return array<int, array{
     *     seconds: array{ready: int, on_call: int, on_break: int, wrapping_up: int},
     *     active: int,
     *     firstLogin: ?Carbon,
     *     lastActivity: ?Carbon,
     *     stints: array<int, array{stint: AgentStatusHistory, startedAt: Carbon, endedAt: ?Carbon, durationSeconds: int}>
     * }>
     */
    public function forAgents(array $agentIds, Carbon $from, Carbon $to, ?int $clientId = null): array
    {
        // AP-2a: the Unassigned row is not a person, so it has no shift — a null id is
        // dropped here rather than being looked up and coming back empty.
        $ids = array_values(array_unique(array_filter($agentIds, fn (?int $id): bool => $id !== null)));

        if ($ids === []) {
            return [];
        }

        $split = [];

        foreach ($ids as $id) {
            $split[$id] = [
                'seconds' => ['ready' => 0, 'on_call' => 0, 'on_break' => 0, 'wrapping_up' => 0],
                'active' => 0,
                'firstLogin' => null,
                'lastActivity' => null,
                'stints' => [],
            ];
        }

        // The board rows, for the dead-session rule. Only a still-open stint needs one,
        // but they are one read for the whole set either way.
        $presences = AgentPresence::query()
            ->whereIn('user_id', $ids)
            ->when($clientId, fn (Builder $query, int $id) => $query->where('tenant_id', $id))
            ->get()
            ->keyBy('user_id');

        $stints = $this->stintsInRange($from, $to, $clientId)
            ->whereIn('user_id', $ids)
            ->orderBy('started_at')
            ->get();

        foreach ($stints as $stint) {
            $agentId = (int) $stint->user_id;

            $effectiveEnd = $stint->effectiveEndedAt($presences->get($agentId));
            $ongoing = $effectiveEnd === null; // genuinely still running (only today)

            $rawEnd = $effectiveEnd ?? now();
            $start = $stint->started_at->greaterThan($from) ? $stint->started_at->copy() : $from->copy();
            $end = $rawEnd->lessThan($to) ? $rawEnd->copy() : $to->copy();
            $duration = $start->lt($end) ? (int) $start->diffInSeconds($end) : 0;

            if (array_key_exists($stint->status->value, $split[$agentId]['seconds'])) {
                $split[$agentId]['seconds'][$stint->status->value] += $duration;
            }

            if ($split[$agentId]['firstLogin'] === null || $start->lt($split[$agentId]['firstLogin'])) {
                $split[$agentId]['firstLogin'] = $start->copy();
            }

            if ($split[$agentId]['lastActivity'] === null || $end->gt($split[$agentId]['lastActivity'])) {
                $split[$agentId]['lastActivity'] = $end->copy();
            }

            $split[$agentId]['stints'][] = [
                'stint' => $stint,
                'startedAt' => $start,
                'endedAt' => $ongoing ? null : $end,
                'durationSeconds' => $duration,
            ];
        }

        foreach ($ids as $id) {
            $split[$id]['active'] = array_sum($split[$id]['seconds']);
        }

        return $split;
    }

    /**
     * Which agents hold any stint that touches the range (apr.md AP-2) — the other half
     * of the Agent Productivity Report's row list. An agent who logged in for eight
     * hours and took no call is exactly the agent a manager wants to see, and the
     * grouped call query cannot know they exist.
     *
     * One read, and a bounded one: AP-12 guarantees the caller always hands over a real
     * range, so this is never the unbounded scan the export screen runs.
     *
     * 🔴 THE NULL GUARD IS NOT DEFENSIVE PADDING (S117). `user_id` is nullable ON
     * PURPOSE — the table orphans a deleted user's stints rather than erasing the
     * evidence (the create migration says so) — so a real delete leaves rows this pluck
     * would otherwise hand back as null, and the whole report threw a TypeError.
     *
     * @return array<int, int>
     */
    public function agentIdsIn(Carbon $from, Carbon $to, ?int $clientId = null): array
    {
        return $this->stintsInRange($from, $to, $clientId)
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('user_id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();
    }

    /**
     * Every stint that overlaps the range: it started at or before the end, and it
     * either never closed or closed at or after the start. Named once so the row list
     * and the seconds behind it can never read two different sets of stints.
     *
     * The client narrowing layers ON TOP of the TenantScope/RLS wall, never around it,
     * the CallReportService posture — a per-client caller stays pinned to their own
     * client whatever this value holds.
     */
    private function stintsInRange(Carbon $from, Carbon $to, ?int $clientId = null): Builder
    {
        return AgentStatusHistory::query()
            ->where('started_at', '<=', $to)
            ->where(function (Builder $query) use ($from): void {
                $query->whereNull('ended_at')->orWhere('ended_at', '>=', $from);
            })
            ->when($clientId, fn (Builder $query, int $id) => $query->where('tenant_id', $id));
    }
}
