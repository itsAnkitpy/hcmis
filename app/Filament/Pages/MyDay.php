<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\CallbackStatus;
use App\Enums\RoleName;
use App\Models\Call;
use App\Models\Callback;
use App\Models\User;
use App\Reporting\CallReportFilters;
use App\Reporting\CallReportService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
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
        return now()->format('l, j F Y').' — midnight to now.';
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
     * "Callbacks due" (MD-3): my pending callbacks due by end of today — overdue
     * included, so nothing promised to a customer can age out of sight.
     */
    public function callbacksDue(): int
    {
        return Callback::query()
            ->where('owner_agent_id', Auth::id())
            ->where('status', CallbackStatus::Pending)
            ->where('scheduled_at', '<=', now()->endOfDay())
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
            ->where('created_at', '>=', now()->startOfDay())
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
            from: now()->startOfDay(),
            to: now(),
            agentId: Auth::id(),
        );
    }
}
