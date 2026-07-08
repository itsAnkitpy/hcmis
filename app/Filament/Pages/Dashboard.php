<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Widgets\CallsByAgentChart;
use App\Filament\Widgets\CallsPerDayChart;
use App\Filament\Widgets\CallStatsOverview;
use App\Filament\Widgets\DirectionSplitChart;
use App\Filament\Widgets\DispositionMixChart;
use App\Filament\Widgets\LiveAvailabilitySnapshot;
use App\Filament\Widgets\OnBreakAgents;
use App\Models\Call;
use App\Models\Campaign;
use App\Models\Tenant;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;
use Illuminate\Support\Facades\Gate;

/**
 * The operations dashboard (Slice 2, RP-6): the panel home, customized into an
 * at-a-glance board — stat tiles, four charts, and a live "who's available now"
 * snapshot. It replaces Filament's default dashboard (registered in
 * AdminPanelProvider), so the scaffold "welcome" / info cards give way to the
 * reporting widgets below.
 *
 * ONE shared filter form (date range + campaign, plus a global-staff client
 * narrowing) drives every widget: HasFiltersForm writes the form state to
 * $this->filters, which Filament hands to each widget as $this->pageFilters
 * (the widgets pull in InteractsWithPageFilters to receive it). Each widget then
 * guard-parses that raw input through CallReportFilters::fromArray, so the tenant
 * wall below is never touched by whatever the browser sends (RP-6 build note).
 *
 * The page itself is NOT gated — it is the panel's home route, so it must stay
 * reachable for everyone. The reporting widgets and this filter form are gated
 * instead (RP-4): a user who cannot view calls sees an empty dashboard, never a
 * 403 off the landing page.
 */
class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    /**
     * The shared filter form (RP-6): date range + campaign, plus the global-staff
     * client narrowing. Rendered only for users who can view the reporting widgets —
     * a lone filter above an empty dashboard would just be clutter for everyone else.
     */
    public function filtersForm(Schema $schema): Schema
    {
        if (! Gate::allows('viewAny', Call::class)) {
            return $schema;
        }

        return $schema->components([
            DatePicker::make('startDate')->label('From'),
            DatePicker::make('endDate')->label('To'),
            Select::make('campaignId')
                ->label('Campaign')
                ->options($this->campaignOptions())
                ->placeholder('All campaigns'),
            // Global staff only (RP-4): narrow the cross-client rollup to one client,
            // or leave blank for all. Hidden for per-client users — the wall already
            // pins them to their own client.
            Select::make('clientId')
                ->label('Client')
                ->options($this->clientOptions())
                ->placeholder('All clients')
                ->visible(fn (): bool => auth()->user()?->operatesGlobally() ?? false),
        ]);
    }

    /**
     * The fixed widget set for the operations dashboard, in display order: the stat
     * tiles first, then the four charts, then the live availability snapshot. An
     * explicit list (rather than the panel-wide discovered widgets) keeps the order
     * deterministic and the board scoped to reporting. Each widget is canView()-gated,
     * so Filament hides them from users who cannot view calls.
     *
     * @return array<class-string<Widget> | WidgetConfiguration>
     */
    public function getWidgets(): array
    {
        return [
            CallStatsOverview::class,
            CallsPerDayChart::class,
            DispositionMixChart::class,
            CallsByAgentChart::class,
            DirectionSplitChart::class,
            LiveAvailabilitySnapshot::class,
            OnBreakAgents::class,
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function campaignOptions(): array
    {
        return Campaign::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * The client list for the global-staff narrowing dropdown (empty + hidden for
     * per-client users). Mirrors the report Pages' clientOptions().
     *
     * @return array<int, string>
     */
    protected function clientOptions(): array
    {
        if (! (auth()->user()?->operatesGlobally() ?? false)) {
            return [];
        }

        return Tenant::query()->orderBy('name')->pluck('name', 'id')->all();
    }
}
