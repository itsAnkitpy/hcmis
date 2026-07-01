<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Enums\CallDirection;
use App\Models\Call;
use App\Models\Campaign;
use App\Models\Tenant;
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
 * Report 1 — Agent productivity (RP-3/RP-5): one row per agent with their call counts
 * + outcome mix for the chosen dates. A custom Page (not a Resource) because the rows
 * are GROUPED aggregates, not one-model-per-row — they are fed by the shared counting
 * layer (CallReportService), the same maths the dashboard charts read (RP-1).
 *
 * Gate + tenant scope are inherited (RP-4): canAccess() calls the Call policy verbatim
 * (Gate::allows('viewAny', Call::class)) so this report can never drift from Call
 * Review's reader set (global HC staff + Team Leader + QC); the service runs in the
 * current tenant context, so each client sees only its own numbers (the wall).
 */
class AgentProductivityReport extends Page
{
    use HasFiltersForm;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Agent productivity';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.reports.agent-productivity';

    public static function canAccess(): bool
    {
        return Gate::allows('viewAny', Call::class);
    }

    /**
     * The shared filter form (RP-5): date range + campaign + direction. The Agent
     * report is already grouped BY agent, so there is no agent filter here.
     */
    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('startDate')->label('From'),
            DatePicker::make('endDate')->label('To'),
            Select::make('campaignId')
                ->label('Campaign')
                ->options($this->campaignOptions())
                ->placeholder('All campaigns'),
            Select::make('direction')
                ->label('Direction')
                ->options($this->directionOptions())
                ->placeholder('All directions'),
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
     * The rendered rows — the counting layer read with the (guard-parsed) filters.
     * The blade renders these; the CSV export streams the exact same array (RP-5).
     *
     * @return array<int, array{agent_id: int|null, agent: string, total: int, inbound: int, outbound: int, contacts: int, sales: int, no_answer: int, with_recording: int, contact_rate: float}>
     */
    public function rows(): array
    {
        return app(CallReportService::class)->agentProductivity(
            CallReportFilters::fromArray($this->filters ?? []),
        );
    }

    public function exportCsv(): StreamedResponse
    {
        $headings = ['Agent', 'Total calls', 'Inbound', 'Outbound', 'Contacts', 'Sales', 'No-answer (provisional)', 'With recording', 'Contact rate %'];

        $data = array_map(fn (array $row): array => [
            $row['agent'], $row['total'], $row['inbound'], $row['outbound'],
            $row['contacts'], $row['sales'], $row['no_answer'], $row['with_recording'], $row['contact_rate'],
        ], $this->rows());

        return CallReportCsv::download('agent-productivity.csv', $headings, $data);
    }

    /**
     * @return array<int, string>
     */
    protected function campaignOptions(): array
    {
        return Campaign::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * The client list for the global-staff narrowing dropdown. Empty for a
     * per-client user (the field is hidden for them anyway); global staff hold no
     * tenant membership, so the full tenant list is the correct set for them.
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
