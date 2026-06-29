<?php

namespace App\Filament\Resources\Calls\Tables;

use App\Enums\CallDirection;
use App\Enums\CallOutcome;
use App\Filament\Support\ClientColumn;
use App\Models\Call;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
                TextColumn::make('duration_seconds')
                    ->label('Duration')
                    ->placeholder('—')
                    ->formatStateUsing(fn (?int $state): string => $state !== null ? gmdate('i:s', $state) : '—'),
                IconColumn::make('recording_path')->label('Rec')->boolean(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
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
                Filter::make('date')
                    ->schema([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('created_at', '<=', $date))),
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
