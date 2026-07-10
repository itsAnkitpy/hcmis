<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\PresenceStatus;
use App\Enums\StintEndedVia;
use App\Models\AgentPresence;
use App\Models\AgentStatusHistory;
use App\Models\Call;
use App\Models\User;
use App\Reporting\CallReportFilters;
use App\Reporting\CallReportService;
use App\Tenancy\TenantContext;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;

/**
 * Agent Detail (AD-1…AD-5) — the per-agent day drill-down a team leader opens to
 * coach one agent: how their shift split across statuses (the Time Sheet) and how
 * their calls turned out (Outcomes). Reached only by link — from the Users list and
 * the Live Agents board (AD-1); never in the nav.
 *
 * The whole page is assembly of pieces already built and tested, so an agent's
 * numbers can never drift from the reports a manager already sees (the coherence
 * rule that kept the Live Agent Board honest):
 *  - the Time Sheet is MyDay::breakMinutes() generalised from "On-break only" to
 *    every status — the same day-clip + effectiveEndedAt dead-session arithmetic;
 *  - Outcomes + total calls read the shared counting layer (CallReportService),
 *    scoped by agentId + the chosen day.
 *
 * Gate (AD-5): Call's viewAny — the exact global-staff / Team Leader / QC audience
 * Call Review and Reporting use; agents have My Day, not this. Row scoping is the
 * tenant wall: the walled tables (agent_status_history, calls) already read empty
 * cross-tenant, and mount() resolves the agent through membership so a per-client
 * leader can never even open an out-of-tenant agent by guessing an id.
 *
 * Honest labels (AD-3): the On-call row is "On-call time," not "Talk time" (it
 * includes ring/hold until the real phone line lands); Duration / IP / Server are
 * omitted now, additive nullable columns later.
 */
class AgentDetail extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected string $view = 'filament.pages.agent-detail';

    /**
     * The agent being coached — resolved through the tenant wall on mount, kept as
     * scalars (Livewire persists them, signed, across the date-picker's re-renders).
     * Not the full model: a public model property would name-collide with the route
     * segment and re-hydrate unscoped on every request.
     */
    public int $agentId;

    public string $agentName;

    /**
     * The single day in view (AD-4), as a query parameter so the picker is
     * shareable/bookmarkable. Null / unparseable / future all fall back to today.
     */
    #[Url]
    public ?string $date = null;

    public static function canAccess(): bool
    {
        return Gate::allows('viewAny', Call::class);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    /**
     * The agent id is a route segment (this page IS one agent's detail), unlike the
     * param-less MyDay / LiveAgents. Verified against Filament v5's page route
     * registration: a standalone page registers a plain GET route, so a {agent}
     * segment is a normal route parameter fed into mount() below.
     */
    public static function getRoutePath(Panel $panel): string
    {
        return '/agent-detail/{record}';
    }

    public function mount(int $record): void
    {
        abort_unless(static::canAccess(), 403);

        $agent = $this->resolveAgent($record);

        $this->agentId = $agent->id;
        $this->agentName = $agent->name;

        // Show the day the page is already displaying in the picker (AD-4). Absent a
        // ?date in the URL the property is null and the native date input renders
        // blank though the page shows today — pre-fill it so the control isn't empty.
        if ($this->date === null) {
            $this->date = now()->toDateString();
        }
    }

    public function getTitle(): string
    {
        return $this->agentName;
    }

    public function getSubheading(): ?string
    {
        return 'Time sheet & outcomes for '.$this->chosenDate()->format('l, j F Y').'.';
    }

    /**
     * The Time Sheet (AD-2 §1) + the day's status timeline, computed in one pass so
     * the blade reads the stints once (the LiveAgents::roster() shape). Seconds per
     * status, Active (= the logged-in span, which is the sum of the four tracked
     * statuses since Offline opens no stint), total calls, first login and last
     * activity, plus the stint list for the timeline.
     *
     * @return array{
     *     seconds: array{ready: int, on_call: int, on_break: int, wrapping_up: int},
     *     active: int,
     *     totalCalls: int,
     *     firstLogin: ?Carbon,
     *     lastActivity: ?Carbon,
     *     timeline: array<int, array{statusLabel: string, statusColor: string, breakCategory: ?string, startedAt: Carbon, endedAt: ?Carbon, durationSeconds: int, endedVia: ?StintEndedVia}>
     * }
     */
    public function daySummary(): array
    {
        $day = $this->chosenDate();
        $dayStart = $day->copy()->startOfDay();
        $dayEnd = $day->copy()->endOfDay();

        // Only today's still-open stint can need the dead-session rule; a past day's
        // stints are all closed. The board row is the evidence effectiveEndedAt reads.
        $presence = AgentPresence::query()->where('user_id', $this->agentId)->first();

        $stints = AgentStatusHistory::query()
            ->where('user_id', $this->agentId)
            ->where('started_at', '<=', $dayEnd)
            ->where(function (Builder $query) use ($dayStart): void {
                $query->whereNull('ended_at')->orWhere('ended_at', '>=', $dayStart);
            })
            ->with('breakCategory')
            ->orderBy('started_at')
            ->get();

        $seconds = ['ready' => 0, 'on_call' => 0, 'on_break' => 0, 'wrapping_up' => 0];
        $timeline = [];
        $firstLogin = null;
        $lastActivity = null;

        foreach ($stints as $stint) {
            $effectiveEnd = $stint->effectiveEndedAt($presence);
            $ongoing = $effectiveEnd === null; // genuinely still running (only today)

            $rawEnd = $effectiveEnd ?? now();
            $start = $stint->started_at->greaterThan($dayStart) ? $stint->started_at->copy() : $dayStart->copy();
            $end = $rawEnd->lessThan($dayEnd) ? $rawEnd->copy() : $dayEnd->copy();
            $duration = $start->lt($end) ? (int) $start->diffInSeconds($end) : 0;

            if (array_key_exists($stint->status->value, $seconds)) {
                $seconds[$stint->status->value] += $duration;
            }

            if ($firstLogin === null || $start->lt($firstLogin)) {
                $firstLogin = $start->copy();
            }

            if ($lastActivity === null || $end->gt($lastActivity)) {
                $lastActivity = $end->copy();
            }

            $timeline[] = [
                'statusLabel' => $stint->status->label(),
                'statusColor' => $this->statusColor($stint->status),
                'breakCategory' => $stint->status === PresenceStatus::OnBreak
                    ? ($stint->breakCategory?->label ?? 'Break')
                    : null,
                'startedAt' => $start,
                'endedAt' => $ongoing ? null : $end,
                'durationSeconds' => $duration,
                'endedVia' => $stint->ended_via,
            ];
        }

        return [
            'seconds' => $seconds,
            'active' => array_sum($seconds),
            'totalCalls' => app(CallReportService::class)->totals($this->dayFilters())['total'],
            'firstLogin' => $firstLogin,
            'lastActivity' => $lastActivity,
            'timeline' => $timeline,
        ];
    }

    /**
     * Outcomes (AD-2 §2) — the disposition mix for this agent + day, most-used
     * first, straight from the same counting layer the manager reports read (so it
     * can never disagree with the agent's Agent Productivity row).
     *
     * @return array<int, array{label: string, count: int, percentage: float}>
     */
    public function outcomes(): array
    {
        return app(CallReportService::class)->dispositionBreakdown($this->dayFilters());
    }

    /**
     * Format a stint duration as "Xh Ym" / "Ym" / "Ss" — the LiveAgents/MyDay
     * clock, extended to seconds so a short stint doesn't read as "0m".
     */
    public function clock(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.'s';
        }

        $minutes = intdiv($seconds, 60);

        if ($minutes < 60) {
            return $minutes.'m';
        }

        return intdiv($minutes, 60).'h '.($minutes % 60).'m';
    }

    /**
     * The chosen day (AD-4): the query-param date parsed defensively — a garbage or
     * future value falls back to today (the CallReportFilters parse posture). Never
     * throws, never lets the picker point past today.
     */
    private function chosenDate(): Carbon
    {
        $today = Carbon::today();

        if (! is_string($this->date) || trim($this->date) === '') {
            return $today;
        }

        $parsed = rescue(fn (): Carbon => Carbon::parse($this->date)->startOfDay(), null, report: false);

        if ($parsed === null || $parsed->greaterThan($today)) {
            return $today;
        }

        return $parsed;
    }

    /**
     * This agent, the chosen day (midnight → end of day), for the counting layer —
     * the same immutable filter object the reports take.
     */
    private function dayFilters(): CallReportFilters
    {
        $day = $this->chosenDate();

        return new CallReportFilters(
            from: $day->copy()->startOfDay(),
            to: $day->copy()->endOfDay(),
            agentId: $this->agentId,
        );
    }

    /**
     * Resolve the target agent through the tenant wall (AD-5). Global staff run
     * cross-tenant and may open any agent (the reports' own posture); a per-client
     * leader may open only an agent who is a member of their current client — a
     * guessed out-of-tenant id 404s rather than rendering an empty page.
     *
     * Users carry no TenantScope (a user can belong to several clients via the
     * user_tenant pivot), so membership is checked explicitly rather than inherited.
     */
    private function resolveAgent(int $id): User
    {
        $query = User::query()->whereKey($id);

        if (! TenantContext::isCrossTenant()) {
            $tenantId = TenantContext::id();

            abort_if($tenantId === null, 404);

            $query->whereHas('tenants', fn (Builder $tenants): Builder => $tenants->whereKey($tenantId));
        }

        return $query->firstOr(fn () => abort(404));
    }

    /**
     * The status pill colour — the same mapping the live board and availability
     * counts use, so a state reads the same colour everywhere.
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
