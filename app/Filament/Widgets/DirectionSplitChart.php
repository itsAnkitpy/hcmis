<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Call;
use App\Reporting\CallReportFilters;
use App\Reporting\CallReportService;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Support\Facades\Gate;

/**
 * Dashboard chart (RP-6): the inbound-vs-outbound split as a doughnut, from the same
 * period totals the stat tiles read (CallReportService::totals). Guard-parses the
 * shared dashboard filter; gated by canView() (RP-4).
 */
class DirectionSplitChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected ?string $heading = 'Inbound vs outbound';

    public static function canView(): bool
    {
        return Gate::allows('viewAny', Call::class);
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $totals = app(CallReportService::class)->totals(
            CallReportFilters::fromArray($this->pageFilters ?? []),
        );

        return [
            'datasets' => [
                [
                    'label' => 'Calls',
                    'data' => [$totals['inbound'], $totals['outbound']],
                ],
            ],
            'labels' => ['Inbound', 'Outbound'],
        ];
    }
}
