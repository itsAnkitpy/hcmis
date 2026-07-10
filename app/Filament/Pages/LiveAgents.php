<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\PresenceStatus;
use App\Models\AgentPresence;
use App\Models\AgentStatusHistory;
use App\Models\Call;
use App\Reporting\CallReportFilters;
use App\Reporting\CallReportService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * Live Agents (LB-1…LB-4) — the team leader's floor board: one row per agent who is
 * on shift right now (DialShree's "Real Time Report"). Everything here is arranged
 * from pieces that already exist — the live status board (AgentPresence, which already
 * reads a quiet screen as Offline), the open break stint (category + limit + overstay,
 * the BK-5 detail), and the per-agent call count (the same counting layer the reports
 * use, so a row can never disagree with the Agent Productivity report).
 *
 * LB-1: only currently-active agents appear — Ready / On a call / Wrapping up / On
 * break. Offline (logged out, or heartbeat gone quiet via effectiveStatus) drops off,
 * so the board stays "who's here now", not a directory. That same staleness rule keeps
 * this coherent with the dashboard counts: a crashed tab left mid-break reads Offline
 * here too, never a phantom "on break".
 *
 * LB-3: the columns are the ones that need NO phone line — name, status, time-in-status,
 * break detail, calls today. The live call timer, waiting-queue, hopper and listen-in
 * columns wait for the trunk (see the spec's "waits for the phone line").
 *
 * Gate (LB): Call's viewAny — the same audience as the dashboard and reports (global
 * staff / team leader / QC). The tenant wall scopes a per-client leader to their own
 * agents for free.
 */
class LiveAgents extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Live Agents';

    protected static ?string $title = 'Live Agents';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.live-agents';

    public static function canAccess(): bool
    {
        return Gate::allows('viewAny', Call::class);
    }

    public function getSubheading(): ?string
    {
        return 'Everyone on the floor right now — refreshes every 15 seconds.';
    }

    /**
     * One row per currently-active agent (LB-1), most-actionable first: on a call,
     * then wrapping up, then ready, then on break — and within a state the longest
     * time-in-status first, so a stretched call or an overstayed break surfaces.
     *
     * Reads three already-built sources: the board rows (kept when effectiveStatus is
     * not Offline), the open status stint per agent (time-in-status + break detail),
     * and today's per-agent call count from the counting layer. All tenant-walled by
     * the request context.
     *
     * @return array<int, array{id: int|null, name: string, status: PresenceStatus, statusLabel: string, statusColor: string, inStatusMinutes: int, breakCategory: string|null, limitMinutes: int|null, overstayed: bool, callsToday: int}>
     */
    public function roster(): array
    {
        $presences = AgentPresence::query()
            ->with('user')
            ->get()
            ->filter(fn (AgentPresence $presence): bool => $presence->effectiveStatus() !== PresenceStatus::Offline);

        if ($presences->isEmpty()) {
            return [];
        }

        $stints = AgentStatusHistory::query()
            ->open()
            ->whereIn('user_id', $presences->pluck('user_id'))
            ->with('breakCategory')
            ->get()
            ->keyBy('user_id');

        $callsToday = collect(app(CallReportService::class)->agentProductivity($this->todayFilters()))
            ->keyBy('agent_id');

        $rows = $presences->map(function (AgentPresence $presence) use ($stints, $callsToday): array {
            $status = $presence->effectiveStatus();
            $stint = $stints->get($presence->user_id);
            $onBreak = $status === PresenceStatus::OnBreak;
            $limit = $onBreak ? $stint?->limit_minutes : null;

            return [
                'id' => $presence->user_id,
                'name' => $presence->user?->name ?? '—',
                'status' => $status,
                'statusLabel' => $status->label(),
                'statusColor' => $this->statusColor($status),
                'inStatusMinutes' => $stint ? (int) $stint->started_at->diffInMinutes(now()) : 0,
                'breakCategory' => $onBreak ? ($stint?->breakCategory?->label ?? 'Break') : null,
                'limitMinutes' => $limit,
                'overstayed' => $limit !== null && now()->greaterThan($stint->started_at->copy()->addMinutes($limit)),
                'callsToday' => (int) ($callsToday->get($presence->user_id)['total'] ?? 0),
            ];
        })->all();

        usort($rows, fn (array $a, array $b): int => [$this->statusOrder($a['status']), -$a['inStatusMinutes']]
            <=> [$this->statusOrder($b['status']), -$b['inStatusMinutes']]);

        return $rows;
    }

    /**
     * The header chips, from the already-built roster (no extra query): the head
     * count, a per-status tally in board order (only states that have someone), and
     * how many are over their break limit.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{total: int, overstayed: int, statuses: array<int, array{label: string, color: string, count: int}>}
     */
    public function summary(array $rows): array
    {
        $counts = [];

        foreach ($rows as $row) {
            $key = $row['status']->value;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $statuses = [];

        foreach ([PresenceStatus::OnCall, PresenceStatus::WrappingUp, PresenceStatus::Ready, PresenceStatus::OnBreak] as $status) {
            $count = $counts[$status->value] ?? 0;

            if ($count > 0) {
                $statuses[] = ['label' => $status->label(), 'color' => $this->statusColor($status), 'count' => $count];
            }
        }

        return [
            'total' => count($rows),
            'overstayed' => count(array_filter($rows, fn (array $row): bool => $row['overstayed'])),
            'statuses' => $statuses,
        ];
    }

    /**
     * All agents, today (midnight → now) — the filter the calls-today column reads
     * through. Built directly: the board has no filter form by design (LB).
     */
    private function todayFilters(): CallReportFilters
    {
        return new CallReportFilters(from: now()->startOfDay(), to: now());
    }

    /**
     * The sort priority within the board — most-actionable state first.
     */
    private function statusOrder(PresenceStatus $status): int
    {
        return match ($status) {
            PresenceStatus::OnCall => 0,
            PresenceStatus::WrappingUp => 1,
            PresenceStatus::Ready => 2,
            PresenceStatus::OnBreak => 3,
            PresenceStatus::Offline => 4, // never shown (LB-1) — kept exhaustive
        };
    }

    /**
     * The status pill colour — the same mapping the live availability counts use, so
     * a state reads the same colour on both screens.
     */
    private function statusColor(PresenceStatus $status): string
    {
        return match ($status) {
            PresenceStatus::Ready => 'success',
            PresenceStatus::OnCall => 'info',
            PresenceStatus::WrappingUp => 'warning',
            PresenceStatus::OnBreak => 'gray',
            PresenceStatus::Offline => 'danger',
        };
    }
}
