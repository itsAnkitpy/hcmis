<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\CallbackStatus;
use App\Enums\PresenceStatus;
use App\Enums\RoleName;
use App\Models\AgentPresence;
use App\Models\AgentStatusHistory;
use App\Models\Call;
use App\Models\Callback;
use App\Models\User;
use App\Reporting\CallReportFilters;
use App\Reporting\CallReportService;
use App\Tenancy\TenantContext;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * My Day (MD-1…MD-4) — the agent's own-day scoreboard: five count tiles, the
 * outcome mix, callbacks due, and today's own calls with in-page play. The first
 * agent-facing read surface, deliberately reopening Reporting v1's "no agent
 * access" exclusion via ONE narrow seam: CallPolicy's own-row `view` exception.
 *
 * Everything on the page is the VIEWING user's own data for the calendar day
 * (midnight → now): the tiles are one agentProductivity() row and the outcomes
 * list one dispositionBreakdown() read — the same counting layer the manager
 * reports use (RP-1), narrowed by agentId, so an agent's numbers can never
 * disagree with their row on the Agent Productivity report. The tenant wall is
 * inherited from the request context, same as every report (RP-4).
 *
 * Gate (MD-2, as amended): Agent role ONLY — deliberately tighter than
 * AgentConsole's agents-plus-global gate, because the page is self-scoped and a
 * global staffer would only ever see an empty scoreboard (managers wanting an
 * agent's numbers have the Agent Productivity report + Call Review). Play is
 * in-page via the gated stream route; downloads stay auditor-only (MD-4).
 */
class MyDay extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSun;

    protected static ?string $navigationLabel = 'My Day';

    protected static ?string $title = 'My Day';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.my-day';

    /** The client's reading zone, resolved on the first row and reused for the rest. */
    private ?string $zone = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        return $user->hasRole(RoleName::Agent->value);
    }

    public function getSubheading(): ?string
    {
        return $this->dayStart()->format('l, j F Y').' — midnight to now.';
    }

    /**
     * The five tiles (MD-3), from the one agentProductivity() row for me + today.
     * No calls yet today = no row = honest zeros.
     *
     * @return array{total: int, contacts: int, sales: int, no_answer: int, contact_rate: float}
     */
    public function tiles(): array
    {
        $row = app(CallReportService::class)->agentProductivity($this->todayFilters())[0] ?? null;

        return [
            'total' => $row['total'] ?? 0,
            'contacts' => $row['contacts'] ?? 0,
            'sales' => $row['sales'] ?? 0,
            'no_answer' => $row['no_answer'] ?? 0,
            'contact_rate' => $row['contact_rate'] ?? 0.0,
        ];
    }

    /**
     * "My outcomes today" (MD-3) — the disposition mix of my day, most-used first.
     *
     * @return array<int, array{label: string, count: int, percentage: float}>
     */
    public function outcomes(): array
    {
        return app(CallReportService::class)->dispositionBreakdown($this->todayFilters());
    }

    /**
     * "Break time" (MD-3's reopened tile — break-tracking slice 5): minutes I've
     * spent OnBreak today. Closed stints count their real span; my open break
     * counts up to now; and BK-6's read rule applies — an open stint whose
     * session died counts only up to the stale cutoff (the same arithmetic the
     * lazy close stamps, AgentStatusHistory::effectiveEndedAt), so a dangling
     * row from a crashed tab never inflates today. Stints clip to the day
     * window: a break spanning midnight counts only today's part. Own rows only
     * by user id; the tenant wall is inherited like everything on this page.
     */
    public function breakMinutes(): int
    {
        $dayStart = $this->dayStart()->utc();

        $presence = AgentPresence::query()->where('user_id', Auth::id())->first();

        $seconds = AgentStatusHistory::query()
            ->where('user_id', Auth::id())
            ->where('status', PresenceStatus::OnBreak)
            ->where(function ($query) use ($dayStart): void {
                $query->whereNull('ended_at')->orWhere('ended_at', '>=', $dayStart);
            })
            ->get()
            ->sum(function (AgentStatusHistory $stint) use ($presence, $dayStart): float {
                $end = $stint->effectiveEndedAt($presence) ?? now();
                $start = $stint->started_at->greaterThan($dayStart) ? $stint->started_at : $dayStart;

                return $start->lt($end) ? $start->diffInSeconds($end) : 0;
            });

        return (int) round($seconds / 60);
    }

    /**
     * "Callbacks due" (MD-3): my pending callbacks due by end of today — overdue
     * included, so nothing promised to a customer can age out of sight.
     */
    public function callbacksDue(): int
    {
        return Callback::query()
            ->where('owner_agent_id', Auth::id())
            ->where('status', CallbackStatus::Pending)
            ->where('scheduled_at', '<=', $this->dayStart()->endOfDay()->utc())
            ->count();
    }

    /**
     * The "my calls" list (MD-4): my calls, today, newest first. lead + campaign
     * eager-loaded for the customer / campaign cells.
     *
     * @return Collection<int, Call>
     */
    public function myCalls(): Collection
    {
        return Call::query()
            ->with(['lead', 'campaign'])
            ->where('agent_id', Auth::id())
            ->where('created_at', '>=', $this->dayStart()->utc())
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Me + today (midnight → now), the one filter set every number on the page
     * reads through (MD-3) — built directly, not from a form: the page has no
     * filters by design.
     */
    private function todayFilters(): CallReportFilters
    {
        return new CallReportFilters(
            from: $this->dayStart(),
            to: now(),
            agentId: Auth::id(),
        );
    }

    /**
     * When one of my calls happened, on the CLIENT'S clock (S118, CE-10). The list below
     * writes its times in the blade, so Filament's panel-wide reading zone never reaches
     * them. Resolved once per render, not once per row.
     */
    public function clockAt(Carbon $at): string
    {
        return $at->copy()->timezone($this->zone ??= TenantContext::reportTimezone())->format('H:i');
    }

    /**
     * 🔴 MIDNIGHT ON THE CLIENT'S CLOCK, NOT OURS (S118, CE-10a). Every number on this
     * page is "today", and today ran from UTC midnight until now — so an agent on an
     * early shift in India saw an empty page until 05:30 and then watched yesterday's
     * calls appear in "my calls today".
     *
     * Returned in the client's zone so the heading reads their date. Each caller that
     * puts it into a query converts it to UTC first, because a boundary object carrying
     * India time reaches Postgres as its wall-clock text.
     */
    private function dayStart(): Carbon
    {
        return CallReportFilters::clientToday();
    }
}
