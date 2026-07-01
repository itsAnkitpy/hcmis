<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Enums\CallDirection;
use App\Models\Call;
use App\Models\Campaign;
use App\Models\Tenant;
use App\Models\User;
use App\Reporting\CallReportCsv;
use App\Reporting\CallReportFilters;
use App\Reporting\CallReportService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Report 2 — Call & disposition summary (RP-3/RP-5): the call mix by day and the
 * disposition breakdown, over the chosen dates. A custom Page fed by the shared
 * counting layer (RP-1), same as the Agent report — two grouped views instead of one.
 *
 * Gate + tenant scope inherited (RP-4): canAccess() calls the Call policy verbatim;
 * the service runs in the current tenant context, so each client sees only its own.
 */
class CallSummaryReport extends Page
{
    use HasFiltersForm;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Call & disposition summary';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.reports.call-summary';

    public static function canAccess(): bool
    {
        return Gate::allows('viewAny', Call::class);
    }

    /**
     * The shared filter form (RP-5): date range + agent + campaign + direction.
     */
    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('startDate')->label('From'),
            DatePicker::make('endDate')->label('To'),
            Select::make('agentId')
                ->label('Agent')
                ->options($this->agentOptions())
                ->placeholder('All agents'),
            Select::make('campaignId')
                ->label('Campaign')
                ->options($this->campaignOptions())
                ->placeholder('All campaigns'),
            Select::make('direction')
                ->label('Direction')
                ->options($this->directionOptions())
                ->placeholder('All directions'),
            // Global staff only (RP-4): narrow the cross-client rollup to one client.
            Select::make('clientId')
                ->label('Client')
                ->options($this->clientOptions())
                ->placeholder('All clients')
                ->visible(fn (): bool => auth()->user()?->operatesGlobally() ?? false),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Export CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action(fn (): StreamedResponse => $this->exportCsv()),
        ];
    }

    /**
     * The by-day rows (RP-5, view A).
     *
     * @return array<int, array{date: string, total: int, inbound: int, outbound: int, contacts: int, sales: int}>
     */
    public function dailyRows(): array
    {
        return app(CallReportService::class)->callsByDay($this->reportFilters());
    }

    /**
     * The by-disposition rows (RP-5, view B).
     *
     * @return array<int, array{label: string, count: int, percentage: float}>
     */
    public function dispositionRows(): array
    {
        return app(CallReportService::class)->dispositionBreakdown($this->reportFilters());
    }

    /**
     * CSV export of the by-day view (the primary operational grid). The disposition
     * mix is a small on-screen breakdown; the by-day rows are the exportable record.
     */
    public function exportCsv(): StreamedResponse
    {
        $headings = ['Date', 'Total calls', 'Inbound', 'Outbound', 'Contacts', 'Sales'];

        $data = array_map(fn (array $row): array => [
            $row['date'], $row['total'], $row['inbound'], $row['outbound'], $row['contacts'], $row['sales'],
        ], $this->dailyRows());

        return CallReportCsv::download('call-summary-by-day.csv', $headings, $data);
    }

    protected function reportFilters(): CallReportFilters
    {
        return CallReportFilters::fromArray($this->filters ?? []);
    }

    /**
     * The agents present in this client's calls — the tenant wall scopes the source
     * query, so a per-client user only ever sees their own agents in the dropdown.
     *
     * @return array<int, string>
     */
    protected function agentOptions(): array
    {
        $agentIds = Call::query()
            ->whereNotNull('agent_id')
            ->distinct()
            ->pluck('agent_id')
            ->all();

        if ($agentIds === []) {
            return [];
        }

        return User::query()
            ->whereIn('id', $agentIds)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
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
     * per-client users). See AgentProductivityReport::clientOptions().
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

    /**
     * @return array<string, string>
     */
    protected function directionOptions(): array
    {
        return collect(CallDirection::cases())
            ->mapWithKeys(fn (CallDirection $direction): array => [$direction->value => $direction->label()])
            ->all();
    }
}
