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
 * Dashboard chart (RP-6): total calls per agent, drawn from the same per-agent maths
 * the Agent productivity report shows (CallReportService::agentProductivity), busiest
 * first. Guard-parses the shared dashboard filter; gated by canView() (RP-4).
 */
class CallsByAgentChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected ?string $heading = 'Calls by agent';

    public static function canView(): bool
    {
        return Gate::allows('viewAny', Call::class);
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $rows = app(CallReportService::class)->agentProductivity(
            CallReportFilters::fromArray($this->pageFilters ?? []),
        );

        return [
            'datasets' => [
                [
                    'label' => 'Calls',
                    'data' => array_column($rows, 'total'),
                ],
            ],
            'labels' => array_column($rows, 'agent'),
        ];
    }
}
