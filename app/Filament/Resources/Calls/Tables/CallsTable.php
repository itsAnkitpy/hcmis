<?php

namespace App\Filament\Resources\Calls\Tables;

use App\Enums\CallDirection;
use App\Enums\CallOutcome;
use App\Filament\Support\ClientColumn;
use App\Models\Call;
use App\Reporting\CallReportFilters;
use App\Support\PhoneNumber;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The Call Review list (CR-4): read-only, filterable by agent / client / campaign
 * / direction / outcome / date / has-recording. A View action plus Play + Download
 * for the recording (the gated stream route, CR-2) when one is present. No edit, no
 * delete, no bulk, no toolbar — a call record is an immutable fact. Lean by design:
 * averages / AHT / charts are the separate reporting module (BRD §6.10), not here.
 */
class CallsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => ClientColumn::eagerLoad($query->with(['agent', 'campaign'])))
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
                ClientColumn::make(),
                TextColumn::make('direction')
                    ->badge()
                    ->formatStateUsing(fn (CallDirection $state): string => $state->label())
                    ->sortable(),
                TextColumn::make('agent.name')->label('Agent')->placeholder('—')->searchable(),
                TextColumn::make('campaign.name')->label('Campaign')->placeholder('—'),
                TextColumn::make('customer_number')
                    ->label('Number')
                    ->state(fn (Call $record): ?string => $record->direction === CallDirection::Outbound
                        ? $record->to_number
                        : $record->from_number)
                    ->placeholder('—'),
                TextColumn::make('outcome')
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(fn (?CallOutcome $state): string => $state?->label() ?? '—')
                    ->sortable(),
                // CT-7: how long this customer waited before somebody picked up. The one
                // new thing on this screen, and the reason the slice exists — every wait
                // figure a report can quote starts here.
                TextColumn::make('waited')
                    ->label('Waited')
                    ->state(fn (Call $record): string => Call::asClock($record->waitedSeconds()))
                    ->tooltip('How long this customer waited before an agent picked up.'),
                // CT-4: computed from pickup -> hang-up, not read from the retired
                // duration column. "Duration" now means one thing on every screen.
                TextColumn::make('duration')
                    ->label('Talked')
                    ->state(fn (Call $record): string => Call::asClock($record->talkedSeconds())),
                // hold.md H-3: next to Waited and Talked, where the eye already looks.
                // Talked stops at the moment the caller is parked and starts again when
                // they come back; this is the gap between the two.
                TextColumn::make('held')
                    ->label('Held')
                    ->state(fn (Call $record): string => Call::asClock($record->hold_seconds))
                    ->tooltip('How long this caller spent on hold, across every hold on the call.'),
                IconColumn::make('recording_path')->label('Rec')->boolean(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                // CH-3 (customer-history-panel.md): one customer's whole history, on the
                // screen that already has the filters, the client's-clock date cut, the
                // View page and the gated recording player. A dedicated customer page
                // would re-implement all four.
                //
                // Runs through the SAME Call::forCustomerNumber() scope the agent's
                // ring-time panel reads (CH-1), so a supervisor and an agent can never be
                // shown different histories for one number. The scope normalizes, so a
                // number typed with spaces or brackets still finds its calls.
                Filter::make('number')
                    ->schema([
                        TextInput::make('value')
                            ->label('Customer number')
                            ->tel()
                            ->placeholder('Any number'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $q): Builder => $q->forCustomerNumber($data['value']),
                    ))
                    // Without this, arriving from the Leads list's History action lands on
                    // a filtered list with nothing on screen saying why it is short.
                    ->indicateUsing(fn (array $data): ?string => filled($data['value'] ?? null)
                        ? 'Number: '.PhoneNumber::normalize($data['value'])
                        : null),
                SelectFilter::make('agent_id')->label('Agent')->relationship('agent', 'name'),
                SelectFilter::make('campaign_id')->label('Campaign')->relationship('campaign', 'name'),
                SelectFilter::make('tenant')->label('Client')->relationship('tenant', 'name'),
                SelectFilter::make('direction')->options(fn (): array => collect(CallDirection::cases())
                    ->mapWithKeys(fn (CallDirection $d): array => [$d->value => $d->label()])->all()),
                SelectFilter::make('outcome')->options(fn (): array => collect(CallOutcome::cases())
                    ->mapWithKeys(fn (CallOutcome $o): array => [$o->value => $o->label()])->all()),
                TernaryFilter::make('recording_path')
                    ->label('Recording')
                    ->placeholder('All calls')
                    ->trueLabel('With recording')
                    ->falseLabel('Without recording')
                    ->queries(
                        true: fn (Builder $q): Builder => $q->whereNotNull('recording_path'),
                        false: fn (Builder $q): Builder => $q->whereNull('recording_path'),
                        blank: fn (Builder $q): Builder => $q,
                    ),
                // 🔴 THE DAY IS CUT ON THE CLIENT'S CLOCK (S118, CE-10a), the same as the
                // Call Export and the reports. `whereDate` compared the raw UTC date, so
                // this list and the export named two different sets of calls for one
                // chosen day — five and a half hours apart at each edge on an India floor.
                //
                // The end boundary is half-open — the day AFTER, exclusive (CE-4a) — and
                // the extra day is added IN THE CLIENT'S ZONE before the conversion, so a
                // zone with daylight saving does not land an hour out twice a year.
                Filter::make('date')
                    ->schema([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(CallReportFilters::clientDayStart($data['from'] ?? null), fn (Builder $q, Carbon $from): Builder => $q->where('created_at', '>=', $from))
                        ->when(CallReportFilters::clientDayStart($data['until'] ?? null, 1), fn (Builder $q, Carbon $until): Builder => $q->where('created_at', '<', $until))),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('play')
                    ->icon(Heroicon::Play)
                    ->color('gray')
                    ->url(fn (Call $record): string => route('calls.recording', $record))
                    ->openUrlInNewTab()
                    ->visible(fn (Call $record): bool => filled($record->recording_path)),
                Action::make('download')
                    ->icon(Heroicon::ArrowDownTray)
                    ->color('gray')
                    ->url(fn (Call $record): string => route('calls.recording', ['record' => $record, 'download' => 1]))
                    ->visible(fn (Call $record): bool => filled($record->recording_path)),
            ])
            ->toolbarActions([]);
    }
}
