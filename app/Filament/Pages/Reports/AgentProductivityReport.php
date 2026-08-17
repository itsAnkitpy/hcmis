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
use App\Reporting\ShiftSplit;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Report 1 — Agent productivity (RP-3/RP-5, extended by apr.md AP-1…AP-13): one row per
 * agent answering the manager's one question — how did they spend the range, and what
 * did they get done? A custom Page (not a Resource) because the rows are GROUPED
 * aggregates, not one-model-per-row.
 *
 * 🔴 THE TIME COLUMNS WERE ADDED TO THIS PAGE, NOT TO A SECOND ONE (AP-1). It has been
 * live since S63 with the call counts on it. Two pages named for the same thing is how
 * a floor ends up with two answers to one question.
 *
 * Two shared readers, no arithmetic of its own: CallReportService counts the calls and
 * sums the handling time in the database (AP-6), ShiftSplit splits the shift across the
 * statuses (AP-3/AP-4) — the same piece Agent Detail's Time Sheet reads, so an agent's
 * own page and this manager's row can never disagree. This class only merges the two,
 * divides once for occupancy, and orders the result.
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
     * The rendered rows — how each agent spent the range and what they got done, one
     * row per agent. The blade renders these; the CSV export streams the exact same
     * array (RP-5).
     *
     * 🔴 THE ROW LIST IS THE UNION OF TWO SETS (apr.md AP-2): agents with calls, from
     * the grouped call query, and agents with a shift, from the stint table. An agent
     * who logged in for eight hours and took no call has no call row at all, and is
     * exactly the agent a manager opens this report to find. Both queries are
     * tenant-walled already, and both were needed anyway, so the union costs one extra
     * read for the names the counting layer never looked up.
     *
     * @return array<int, array{agent_id: int|null, agent: string, total: int, inbound: int, outbound: int, contacts: int, sales: int, no_answer: int, with_recording: int, contact_rate: float, answered: int, talk_seconds: int, hold_seconds: int, wrap_seconds: int, aht_seconds: int|null, active_seconds: int|null, ready_seconds: int|null, on_call_seconds: int|null, wrapping_up_seconds: int|null, on_break_seconds: int|null, occupancy: float|null}>
     */
    public function rows(): array
    {
        $parsed = CallReportFilters::fromArray($this->filters ?? []);

        // 🔴 AP-12. A blank date box reads TODAY, and the guard sits HERE rather than
        // as a form default, because a form default fires on first load only — a
        // manager who CLEARS the boxes would otherwise drop straight back to every
        // record ever. The call side rides an index and survives that; the stint walk
        // happens in PHP and would not, on a real floor after a year.
        $from = $parsed->from ?? Carbon::today()->startOfDay();
        $to = $parsed->to ?? Carbon::today()->endOfDay();

        $filters = new CallReportFilters(
            from: $from,
            to: $to,
            agentId: $parsed->agentId,
            campaignId: $parsed->campaignId,
            direction: $parsed->direction,
            clientId: $parsed->clientId,
        );

        $rows = app(CallReportService::class)->agentProductivity($filters);
        $split = app(ShiftSplit::class);

        $calledIds = array_values(array_filter(
            array_column($rows, 'agent_id'),
            fn (?int $id): bool => $id !== null,
        ));
        $stintIds = $split->agentIdsIn($from, $to);

        foreach ($this->agentNames(array_values(array_diff($stintIds, $calledIds))) as $id => $name) {
            $rows[] = $this->shiftOnlyRow((int) $id, $name);
        }

        $shifts = $split->forAgents([...$calledIds, ...$stintIds], $from, $to);

        $rows = array_map(fn (array $row): array => $row + $this->shiftColumns(
            $row['agent_id'] === null ? null : ($shifts[$row['agent_id']] ?? null),
            $row['talk_seconds'] + $row['hold_seconds'] + $row['wrap_seconds'],
        ), $rows);

        // AP-2a: busiest first, the ranking this screen has carried since S63, then by
        // name. The tiebreak is not decoration — once the no-call agents join they all
        // tie on zero, and a tie with no tiebreak reshuffles between two reads of the
        // same screen.
        usort($rows, fn (array $a, array $b): int => [$b['total'], $a['agent']] <=> [$a['total'], $b['agent']]);

        return $rows;
    }

    /**
     * 🔴 CE-4's honesty rule, applied to the shift (AP-2a, AP-7a). BLANK, NEVER ZERO,
     * when the range holds no shift for this row: the Unassigned row is not a person so
     * it has no shift at all, and a range reaching back before the stint log started
     * (S74) has no history to show for anybody. "0h" claims we measured a shift and
     * found none; blank says we have no record, which is what happened.
     *
     * @param  array{seconds: array{ready: int, on_call: int, on_break: int, wrapping_up: int}, active: int}|null  $shift
     * @return array{active_seconds: int|null, ready_seconds: int|null, on_call_seconds: int|null, wrapping_up_seconds: int|null, on_break_seconds: int|null, occupancy: float|null}
     */
    private function shiftColumns(?array $shift, int $handlingSeconds): array
    {
        if ($shift === null || $shift['active'] === 0) {
            return [
                'active_seconds' => null,
                'ready_seconds' => null,
                'on_call_seconds' => null,
                'wrapping_up_seconds' => null,
                'on_break_seconds' => null,
                'occupancy' => null,
            ];
        }

        return [
            'active_seconds' => $shift['active'],
            'ready_seconds' => $shift['seconds']['ready'],
            'on_call_seconds' => $shift['seconds']['on_call'],
            'wrapping_up_seconds' => $shift['seconds']['wrapping_up'],
            'on_break_seconds' => $shift['seconds']['on_break'],
            // 🔴 AP-7a, and deliberately NOT the service's rate() helper: that answers
            // 0.0 on a zero bottom, and 0% reads as "this agent did nothing" about
            // somebody who handled calls. Above 100 prints as it is — a call joins the
            // range by its Done click while shift time is trimmed to the range, so a
            // call that began earlier drags its whole handling time in. The (i) on the
            // column says so.
            'occupancy' => round($handlingSeconds / $shift['active'] * 100, 1),
        ];
    }

    /**
     * The call half of a row for an agent who worked the range and took no call (AP-2).
     * Every count is a real zero — we read the calls and there were none — so none of
     * these is blanked. `aht_seconds` stays null: an average over no calls does not
     * exist, and 0 would claim an instant handle time.
     *
     * @return array{agent_id: int, agent: string, total: int, inbound: int, outbound: int, contacts: int, sales: int, no_answer: int, with_recording: int, contact_rate: float, answered: int, talk_seconds: int, hold_seconds: int, wrap_seconds: int, aht_seconds: int|null}
     */
    private function shiftOnlyRow(int $agentId, string $agentName): array
    {
        return [
            'agent_id' => $agentId,
            'agent' => $agentName,
            'total' => 0,
            'inbound' => 0,
            'outbound' => 0,
            'contacts' => 0,
            'sales' => 0,
            'no_answer' => 0,
            'with_recording' => 0,
            'contact_rate' => 0.0,
            'answered' => 0,
            'talk_seconds' => 0,
            'hold_seconds' => 0,
            'wrap_seconds' => 0,
            'aht_seconds' => null,
        ];
    }

    /**
     * Names for the agents who have a shift but no calls. The counting layer never saw
     * them, so it never looked their names up.
     *
     * @param  array<int, int>  $agentIds
     * @return array<int, string>
     */
    private function agentNames(array $agentIds): array
    {
        return $agentIds === []
            ? []
            : User::query()->whereIn('id', $agentIds)->pluck('name', 'id')->all();
    }

    /**
     * The CSV carries every column the screen does, in the same order (RP-5).
     *
     * DURATIONS GO OUT AS SECONDS, not as the screen's clock. A supervisor totals and
     * sorts these columns in a spreadsheet, and `08:00:00` is text there while `28800`
     * is a number. A blank on screen stays an empty cell here — never a 0.
     */
    public function exportCsv(): StreamedResponse
    {
        $headings = [
            'Agent',
            'Logged-in (s)', 'Ready (s)', 'On a call (s)', 'Wrapping up status (s)', 'On break (s)', 'Occupancy %',
            'Total calls', 'Answered', 'Inbound', 'Outbound', 'Contacts', 'Sales',
            'No-answer (provisional)', 'With recording', 'Contact rate %',
            'Talk (s)', 'Hold (s)', 'Wrap per call (s)', 'AHT (s)',
        ];

        $data = array_map(fn (array $row): array => [
            $row['agent'],
            $row['active_seconds'], $row['ready_seconds'], $row['on_call_seconds'],
            $row['wrapping_up_seconds'], $row['on_break_seconds'], $row['occupancy'],
            $row['total'], $row['answered'], $row['inbound'], $row['outbound'],
            $row['contacts'], $row['sales'], $row['no_answer'], $row['with_recording'], $row['contact_rate'],
            $row['talk_seconds'], $row['hold_seconds'], $row['wrap_seconds'], $row['aht_seconds'],
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
