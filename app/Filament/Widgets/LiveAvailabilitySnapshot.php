<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\PresenceStatus;
use App\Models\AgentPresence;
use App\Models\Call;
use App\Reporting\CallReportFilters;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Gate;

/**
 * The live "who's available now" tile (RP-6): current counts of Ready / On a call /
 * Wrapping up / On break / Offline. This is the ONE dashboard widget that does NOT
 * read the counting layer — it reads current presence, a "right now" snapshot, not a
 * history (we keep none, PD-6).
 *
 * Staleness is computed in PHP, never stored (RP-6 build note): the tally reads each
 * row's effectiveStatus(), so a crashed tab whose stored status still says "Ready" but
 * whose heartbeat has gone quiet is correctly counted as Offline — the board never
 * lies about who could be rung. Tallying in PHP (not a raw GROUP BY status) is what
 * lets that per-row staleness rule apply; cheap at real agent counts (dozens/client).
 *
 * Presence rows are tenant-walled, so a per-client user sees only their own agents;
 * global staff can narrow the rollup to one client via the shared filter (the same
 * clientId seam the counting layer honours). Gated by canView() (RP-4).
 */
class LiveAvailabilitySnapshot extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected ?string $heading = "Who's available now";

    protected ?string $description = 'Current agent status — refresh to update';

    public static function canView(): bool
    {
        return Gate::allows('viewAny', Call::class);
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $counts = $this->statusCounts();

        return array_map(
            fn (PresenceStatus $status): Stat => Stat::make(
                $status->label(),
                $counts[$status->value],
            )->color($this->colorFor($status)),
            PresenceStatus::cases(),
        );
    }

    /**
     * Tally the client's agents by their EFFECTIVE status (staleness applied), one
     * count per state so every agent is accounted for and the tiles sum to the head
     * count. Only the global-staff client narrowing touches the query (the date range
     * is meaningless for a "now" snapshot); the tenant wall does the per-client scope.
     *
     * @return array<string, int>
     */
    protected function statusCounts(): array
    {
        $filters = CallReportFilters::fromArray($this->pageFilters ?? []);

        $counts = array_fill_keys(
            array_map(fn (PresenceStatus $status): string => $status->value, PresenceStatus::cases()),
            0,
        );

        AgentPresence::query()
            ->when($filters->clientId, fn ($query, int $id) => $query->where('tenant_id', $id))
            ->get()
            ->each(function (AgentPresence $presence) use (&$counts): void {
                $counts[$presence->effectiveStatus()->value]++;
            });

        return $counts;
    }

    private function colorFor(PresenceStatus $status): string
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
