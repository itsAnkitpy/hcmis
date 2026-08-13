<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Enums\CallDirection;
use App\Enums\CallOutcome;
use App\Models\Call;
use App\Models\Campaign;
use App\Models\Disposition;
use App\Models\Tenant;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * Report 3 — Call export (call-export.md): pick a range and some filters, press
 * Download, get one row per call. The report clients ask for most.
 *
 * The Download control is a LINK to calls.export, not an action returning a response
 * (CE-5b): a Filament button hands its file back through Livewire's JSON channel,
 * which buffers every byte in memory twice and cannot stream. The filters travel as
 * query parameters, and the controller re-validates them because a web address is a
 * trust boundary a form is not (CE-5d).
 *
 * Field names are shared verbatim with the controller's validation rules — the page
 * writes the link and the controller reads it, so the two must agree on spelling.
 * They follow CallReportFilters, the naming the other two report pages already use.
 *
 * Gate inherited (CE-9): canAccess() calls the Call policy, exactly as the other two
 * report pages do; the controller repeats the check by hand because a plain route,
 * unlike a Filament page, does not gate itself.
 */
class CallExportReport extends Page
{
    use HasFiltersForm;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Call export';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.reports.call-export';

    public static function canAccess(): bool
    {
        return Gate::allows('viewAny', Call::class);
    }

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
            Select::make('dispositionId')
                ->label('Disposition')
                ->options($this->dispositionOptions())
                ->placeholder('All dispositions'),
            Select::make('direction')
                ->label('Direction')
                ->options($this->directionOptions())
                ->placeholder('All directions'),
            Select::make('outcome')
                ->label('Outcome')
                ->options($this->outcomeOptions())
                ->placeholder('All outcomes'),
            Select::make('hasRecording')
                ->label('Recording')
                ->options(['1' => 'With recording', '0' => 'Without recording'])
                ->placeholder('All calls'),
            Select::make('durationFormat')
                ->label('Durations as')
                ->options(['seconds' => 'Whole seconds', 'clock' => 'hh:mm:ss'])
                ->default('seconds')
                ->selectablePlaceholder(false),
            // Global staff only (CE-5d): the one filter that changes WHICH client's
            // rows come out. Everyone else is pinned by the tenant wall regardless.
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
                ->label('Download CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->url(fn (): string => $this->downloadUrl()),
        ];
    }

    /**
     * The download address, carrying whatever the filter form currently holds. Blank
     * fields are dropped rather than sent empty, so an untouched form produces a bare
     * URL and the controller's "absent means unfiltered" default does the rest.
     */
    public function downloadUrl(): string
    {
        return route('calls.export', array_filter(
            $this->filters ?? [],
            fn (mixed $value): bool => filled($value),
        ));
    }

    /**
     * The agents present in this client's calls — the tenant wall scopes the source
     * query, so a per-client user only ever sees their own agents.
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
     * @return array<int, string>
     */
    protected function dispositionOptions(): array
    {
        return Disposition::query()->orderBy('label')->pluck('label', 'id')->all();
    }

    /**
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

    /**
     * @return array<string, string>
     */
    protected function outcomeOptions(): array
    {
        return collect(CallOutcome::cases())
            ->mapWithKeys(fn (CallOutcome $outcome): array => [$outcome->value => $outcome->label()])
            ->all();
    }
}
