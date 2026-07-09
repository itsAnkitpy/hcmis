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
 * Dashboard chart (RP-6): calls per calendar day, drawn from the same by-day maths
 * the Call summary report table shows (CallReportService::callsByDay). Guard-parses
 * the shared dashboard filter ($this->pageFilters); gated by canView() (RP-4).
 */
class CallsPerDayChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected ?string $heading = 'Calls per day';

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
        $rows = app(CallReportService::class)->callsByDay(
            CallReportFilters::fromArray($this->pageFilters ?? []),
        );

        return [
            'datasets' => [
                [
                    'label' => 'Calls',
                    // One colour for the whole series (LB Slice 2): the day is already on
                    // the axis, so a colour per day would add noise, not meaning.
                    'backgroundColor' => ChartPalette::PRIMARY,
                    'data' => array_column($rows, 'total'),
                ],
            ],
            'labels' => array_column($rows, 'date'),
        ];
    }
}
