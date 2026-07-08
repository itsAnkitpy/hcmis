<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\PresenceStatus;
use App\Models\AgentPresence;
use App\Models\AgentStatusHistory;
use App\Models\Call;
use App\Reporting\CallReportFilters;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Gate;

/**
 * The live board's per-agent break detail (BK-5): everyone on break right now,
 * by name, with the break type, minutes elapsed, and a red flag once past the
 * category's limit. Sits under the "who's available now" counts on the operations
 * dashboard — same gate (RP-4), same manual-refresh habit, same tenant wall.
 *
 * Overstay is COMPUTED here, never stored (BK-4): now vs. started_at + the limit
 * snapshot the stint carries (BK-2) — an admin's later edit to the category can't
 * rewrite who was red yesterday.
 *
 * BK-6's read-side rule, board-coherent by construction: a row appears only when
 * the agent's presence reads OnBreak through effectiveStatus() — the exact
 * staleness rule the counts above apply. A dangling open stint whose session died
 * counts as Offline up top, so it must not read as "on break" down here; both
 * judgements share one stale window (telephony.presence.stale_after_seconds).
 */
class OnBreakAgents extends Widget
{
    use InteractsWithPageFilters;

    protected string $view = 'filament.widgets.on-break-agents';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Gate::allows('viewAny', Call::class);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return ['rows' => $this->onBreakRows()];
    }

    /**
     * One row per agent on break, longest away first. Reads the OPEN break stints
     * (the history the console's single door writes), then keeps only those whose
     * presence still reads OnBreak — the BK-6 coherence filter above. Untyped
     * breaks (no active categories configured, BK-3's fallback) show as plain
     * "Break" with no limit. Only the global-staff client narrowing touches the
     * query; the tenant wall does the per-client scope.
     *
     * @return array<int, array{name: string, category: string, elapsedMinutes: int, limitMinutes: int|null, overstayed: bool}>
     */
    protected function onBreakRows(): array
    {
        $filters = CallReportFilters::fromArray($this->pageFilters ?? []);

        $stints = AgentStatusHistory::query()
            ->open()
            ->where('status', PresenceStatus::OnBreak)
            ->when($filters->clientId, fn ($query, int $id) => $query->where('tenant_id', $id))
            ->with(['user', 'breakCategory'])
            ->orderBy('started_at')
            ->get();

        $presenceByUser = AgentPresence::query()
            ->whereIn('user_id', $stints->pluck('user_id'))
            ->get()
            ->keyBy('user_id');

        return $stints
            ->filter(fn (AgentStatusHistory $stint): bool => $presenceByUser->get($stint->user_id)?->effectiveStatus() === PresenceStatus::OnBreak)
            ->map(fn (AgentStatusHistory $stint): array => [
                'name' => $stint->user?->name ?? '—',
                'category' => $stint->breakCategory?->label ?? 'Break',
                'elapsedMinutes' => (int) $stint->started_at->diffInMinutes(now()),
                'limitMinutes' => $stint->limit_minutes,
                'overstayed' => $stint->limit_minutes !== null
                    && now()->gt($stint->started_at->copy()->addMinutes($stint->limit_minutes)),
            ])
            ->values()
            ->all();
    }
}
