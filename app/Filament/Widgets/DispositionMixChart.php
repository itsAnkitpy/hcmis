<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Call;
use App\Reporting\CallReportFilters;
use App\Reporting\CallReportService;
use App\Reporting\ChartPalette;
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
        $rows = $this->capToPalette(
            app(CallReportService::class)->dispositionBreakdown(
                CallReportFilters::fromArray($this->pageFilters ?? []),
            ),
        );

        return [
            'datasets' => [
                [
                    'label' => 'Dispositions',
                    'backgroundColor' => ChartPalette::categorical(count($rows)),
                    'data' => array_column($rows, 'count'),
                ],
            ],
            'labels' => array_column($rows, 'label'),
        ];
    }

    /**
     * Keep the donut readable: the palette has a fixed number of distinct hues, so
     * once there are more dispositions than hues the tail folds into a single "Other"
     * slice rather than repeating a colour (the dataviz no-cycle rule). Rows arrive
     * already sorted most-used first, so the tail is genuinely the long thin end.
     *
     * @param  array<int, array{label: string, count: int, percentage: float}>  $rows
     * @return array<int, array{label: string, count: int}>
     */
    private function capToPalette(array $rows): array
    {
        $hues = count(ChartPalette::CATEGORICAL);

        if (count($rows) <= $hues) {
            return $rows;
        }

        $head = array_slice($rows, 0, $hues - 1);
        $tail = array_slice($rows, $hues - 1);

        $head[] = ['label' => 'Other', 'count' => array_sum(array_column($tail, 'count'))];

        return $head;
    }
}
