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
 * Today's call numbers beside yesterday's — three pairs of bars: Total, Inbound,
 * Outbound (S92 slice 4, DialShree parity). It answers "are we up or down" without
 * anyone having to read a line graph.
 *
 * This is DialShree's "Stats Graphical Report" shape: a today bar and a yesterday
 * bar per measure. It is NOT the same thing as CallsPerDayChart, which stays as it
 * is — that one is one bar per calendar day across the chosen range, so days are
 * already its horizontal axis and there is nowhere sensible to lay "yesterday".
 *
 * Their fourth measure (Maximum Agents) is deliberately dropped: an agent head count
 * of ~15 sharing a vertical axis with ~1,400 calls draws as an invisible sliver, and
 * it is the only one of the four not already free out of totals(). Same
 * two-populations-under-one-heading mistake as their Agent Status donut (S91).
 *
 * Like the counter strip above it, this block IGNORES the page's date range — the two
 * days are the whole point of it — and its description says so out loud, so a manager
 * who sets the range to last week is not left wondering why the bars did not move. The
 * global-staff client narrowing IS honoured (the shared clientId seam, RP-4), like
 * every other widget on the board. Gated by canView() (RP-4).
 *
 * The "hide yesterday" toggle DialShree shows as a struck-through legend entry comes
 * free: clicking a legend entry hides that series already.
 */
class TodayVsYesterdayChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected ?string $heading = 'Today vs yesterday';

    protected ?string $description = 'Today beside yesterday, whole days — not affected by the date range above.';

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
        $clientId = CallReportFilters::fromArray($this->pageFilters ?? [])->clientId;
        $service = app(CallReportService::class);

        // Today runs to now, not to end of day: a bar drawn to midnight would claim
        // hours that have not happened yet. Yesterday is the whole closed day.
        $today = $service->totals(new CallReportFilters(
            from: now()->startOfDay(),
            to: now(),
            clientId: $clientId,
        ));

        $yesterday = $service->totals(new CallReportFilters(
            from: now()->subDay()->startOfDay(),
            to: now()->subDay()->endOfDay(),
            clientId: $clientId,
        ));

        // Two series, so two hues in the palette's fixed order — never hand-picked.
        [$todayColour, $yesterdayColour] = ChartPalette::categorical(2);

        return [
            'datasets' => [
                [
                    'label' => 'Today',
                    'backgroundColor' => $todayColour,
                    'data' => [$today['total'], $today['inbound'], $today['outbound']],
                ],
                [
                    'label' => 'Yesterday',
                    'backgroundColor' => $yesterdayColour,
                    'data' => [$yesterday['total'], $yesterday['inbound'], $yesterday['outbound']],
                ],
            ],
            'labels' => ['Total calls', 'Inbound', 'Outbound'],
        ];
    }
}
