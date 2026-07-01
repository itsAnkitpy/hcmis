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
 * Dashboard chart (RP-6): the disposition mix as a doughnut, drawn from the same
 * breakdown the Call summary report shows (CallReportService::dispositionBreakdown).
 * Only dispositioned calls appear (ad-hoc calls have none). Guard-parses the shared
 * dashboard filter; gated by canView() (RP-4).
 */
class DispositionMixChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected ?string $heading = 'Disposition mix';

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
        $rows = app(CallReportService::class)->dispositionBreakdown(
            CallReportFilters::fromArray($this->pageFilters ?? []),
        );

        return [
            'datasets' => [
                [
                    'label' => 'Dispositions',
                    'data' => array_column($rows, 'count'),
                ],
            ],
            'labels' => array_column($rows, 'label'),
        ];
    }
}
