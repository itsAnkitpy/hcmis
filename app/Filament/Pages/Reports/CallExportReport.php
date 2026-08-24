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
use App\Reporting\CallExportRows;
use App\Tenancy\TenantContext;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;
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

    /** Match the sidebar. Left unset, Filament builds "Call Export Report" from the class name. */
    protected static ?string $title = 'Call export';

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
        return route('calls.export', $this->exportFilters());
    }

    /**
     * What the Download button is about to produce, read back in the same words as the
     * filters — "Ready to export 6 calls · 12 Aug 2026 to 13 Aug 2026 · agent Abhikesh".
     *
     * WHY this exists (S107, from a real staging confusion). The other two report pages
     * put a table on screen, so a stale filter is visible before you export: the numbers
     * in front of you change. This page has no table by design — the client picks WHICH
     * CALLS, never which columns (CE-1) — so a filter left over from earlier is invisible
     * until the spreadsheet is already open, and the file looks like it lost rows. That
     * is exactly the "a truncated export that looks complete is worse than no export"
     * failure CE-3 refuses for; the same reasoning applies to a filtered one.
     *
     * The count comes from CallExportRows::query() — the SAME builder the download walks
     * (CE-5a's one-builder rule), so the number promised here and the rows written can
     * never disagree. It is also the count CE-3's row cap needs, arriving one step early.
     *
     * CE-3's refusal lives on the route, because a web address is the guard and a screen
     * is not. This line is the earlier, kinder half of the same rule: the supervisor is
     * told the range is too big while they can still change it, instead of finding out by
     * being turned around after pressing Download.
     */
    public function exportSummary(): string
    {
        $count = (new CallExportRows($this->exportFilters()))->query()->count();
        $applied = $this->appliedFilters();
        $where = $applied === [] ? 'no filters set, so every call you can see' : implode(' · ', $applied);
        $limit = CallExportRows::maxRows();

        if ($count > $limit) {
            return sprintf(
                'That is %s calls — %s — which is over the %s the export can write. Shorten the date range, or add a filter.',
                number_format($count),
                $where,
                number_format($limit),
            );
        }

        return sprintf('Ready to export %s %s — %s.', number_format($count), $count === 1 ? 'call' : 'calls', $where);
    }

    /**
     * CE-12a. The extra customer columns appear only when the export is narrowed to one
     * campaign, because a spreadsheet's heading row is written once and two campaigns
     * can define two different sets of fields. Said on screen so it is a rule the
     * supervisor can see, not a surprise they find in the file.
     */
    public function customFieldsNote(): ?string
    {
        $campaign = filled($this->filters['campaignId'] ?? null)
            ? Campaign::find((int) $this->filters['campaignId'])
            : null;

        $fields = collect($campaign?->custom_fields ?? [])
            ->pluck('label')
            ->filter()
            ->all();

        if ($campaign === null) {
            return 'Pick a single campaign to also export its own customer fields as extra columns.';
        }

        return $fields === []
            ? sprintf('%s has no custom customer fields, so the file holds the standard columns only.', $campaign->name)
            : sprintf('Plus %s\'s own customer fields as extra columns: %s.', $campaign->name, implode(', ', $fields));
    }

    /**
     * Every filter currently in force, in plain words. The list is what makes a
     * leftover selection obvious; the count alone would only say the number is small.
     *
     * @return array<int, string>
     */
    public function appliedFilters(): array
    {
        $filters = $this->exportFilters();
        $describe = fn (string $key, string $label, callable $name): ?string => filled($filters[$key] ?? null)
            ? $label.' '.($name((int) $filters[$key]) ?? '#'.$filters[$key])
            : null;

        return array_values(array_filter([
            $this->describeDates($filters['startDate'] ?? null, $filters['endDate'] ?? null),
            $describe('agentId', 'agent', fn (int $id): ?string => User::find($id)?->name),
            $describe('campaignId', 'campaign', fn (int $id): ?string => Campaign::find($id)?->name),
            $describe('dispositionId', 'disposition', fn (int $id): ?string => Disposition::find($id)?->label),
            $describe('clientId', 'client', fn (int $id): ?string => Tenant::find($id)?->name),
            filled($filters['direction'] ?? null)
                ? CallDirection::from($filters['direction'])->label().' only'
                : null,
            filled($filters['outcome'] ?? null)
                ? 'outcome '.CallOutcome::from($filters['outcome'])->label()
                : null,
            match ($filters['hasRecording'] ?? null) {
                null, '' => null,
                '0', 0, false => 'without a recording',
                default => 'with a recording',
            },
        ], fn (?string $part): bool => $part !== null));
    }

    /** The date range as a person would say it. */
    private function describeDates(?string $from, ?string $until): ?string
    {
        $day = fn (string $date): string => CarbonImmutable::parse($date)->format('j M Y');

        return match (true) {
            filled($from) && filled($until) => $from === $until ? 'on '.$day($from) : $day($from).' to '.$day($until),
            filled($from) => 'from '.$day($from),
            filled($until) => 'up to '.$day($until),
            default => null,
        };
    }

    /**
     * The filter form's state with blanks dropped — the one shape both the download link
     * and the summary read, so what the line promises is what the link asks for.
     *
     * @return array<string, mixed>
     */
    private function exportFilters(): array
    {
        return array_filter($this->filters ?? [], fn (mixed $value): bool => filled($value));
    }

    /**
     * The agents present in this client's calls — the tenant wall scopes the source
     * query, so a per-client user only ever sees their own agents.
     *
     * 🔴 CACHED (S119 N3), because the inner walk is a DISTINCT over every call this
     * client has ever made and it ran on EVERY render of the filter form — each keystroke
     * in a date box, each other dropdown touched, on a table that only grows.
     *
     * The cheaper-looking fix does not exist. `users` carries no tenant column — membership
     * is the `user_tenant` pivot — so reading that table directly would hand a per-client
     * reader every other client's staff names. Deriving the id set from the WALLED calls
     * query is what keeps the two apart, which is why the key below is the tenant, and why
     * global staff (who legitimately read across clients) get their own key.
     *
     * ponytail: five minutes of staleness — an agent's very FIRST call may not name them
     * here for up to five minutes; every later call is unaffected. Shorten the window, or
     * key on the chosen date range as well, if a floor ever notices.
     *
     * @return array<int, string>
     */
    protected function agentOptions(): array
    {
        return Cache::remember(
            'call-export:agent-options:'.(TenantContext::id() ?? 'global'),
            now()->addMinutes(5),
            function (): array {
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
            },
        );
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
