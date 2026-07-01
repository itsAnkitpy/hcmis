<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Call;
use App\Reporting\CallReportFilters;
use App\Reporting\CallReportService;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Gate;

/**
 * Dashboard stat tiles (RP-6): the period big-numbers — Total calls · Contacts ·
 * Sales · Recording coverage. Reads the SAME counting layer the report tables use
 * (CallReportService::totals), so a tile can never disagree with its table.
 *
 * The shared dashboard filter arrives as $this->pageFilters (unvalidated live input)
 * and is guard-parsed by CallReportFilters::fromArray before the query — the tenant
 * wall sits below, untouched. Gated by canView() (RP-4): a user who cannot view calls
 * never sees the tiles.
 */
class CallStatsOverview extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected ?string $heading = 'Call summary';

    public static function canView(): bool
    {
        return Gate::allows('viewAny', Call::class);
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $totals = app(CallReportService::class)->totals(
            CallReportFilters::fromArray($this->pageFilters ?? []),
        );

        return [
            Stat::make('Total calls', $totals['total'])
                ->description('All calls in range')
                ->color('primary'),
            Stat::make('Contacts', $totals['contacts'])
                ->description('Reached a person')
                ->color('info'),
            Stat::make('Sales', $totals['sales'])
                ->description('Marked as sold')
                ->color('success'),
            Stat::make('Recording coverage', $totals['recording_coverage'].'%')
                ->description('Calls with a recording')
                ->color('warning'),
        ];
    }
}
