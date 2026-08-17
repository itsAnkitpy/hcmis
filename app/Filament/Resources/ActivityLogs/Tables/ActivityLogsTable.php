<?php

namespace App\Filament\Resources\ActivityLogs\Tables;

use App\Filament\Support\ClientColumn;
use App\Models\ActivityLog;
use App\Models\User;
use App\Reporting\CallReportFilters;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The audit-log list (D-M7-4). Read-only: a single ViewAction, no edit/delete,
 * no bulk actions. Filterable by area, action, client, user and date — the
 * "queryable by user / tenant / date" exit criterion for M7.
 */
class ActivityLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => ClientColumn::eagerLoad($query->with('causer')))
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
                ClientColumn::make(),
                TextColumn::make('log_name')->label('Area')->badge()->placeholder('—'),
                TextColumn::make('event')->label('Action')->badge()->placeholder('—')->sortable(),
                TextColumn::make('description')->limit(50)->wrap(),
                TextColumn::make('causer.name')->label('Who')->placeholder('System'),
                TextColumn::make('subject_type')
                    ->label('Record')
                    ->formatStateUsing(fn (?string $state): string => $state ? class_basename($state) : '—')
                    ->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('log_name')
                    ->label('Area')
                    ->options(fn (): array => ActivityLog::query()
                        ->distinct()->orderBy('log_name')->pluck('log_name', 'log_name')->filter()->all()),
                SelectFilter::make('event')
                    ->label('Action')
                    ->options(fn (): array => ActivityLog::query()
                        ->whereNotNull('event')->distinct()->orderBy('event')->pluck('event', 'event')->all()),
                SelectFilter::make('tenant')
                    ->label('Client')
                    ->relationship('tenant', 'name'),
                SelectFilter::make('causer_id')
                    ->label('User')
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all()),
                // 🔴 THE DAY IS CUT ON THE CLIENT'S CLOCK (S118, CE-10a), the same as the
                // Calls list, the reports and the Call Export. The When column above now
                // prints on that clock, and a filter still cutting on the raw stored date
                // would hide rows the reader can see the timestamps of.
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
            ])
            ->toolbarActions([]);
    }
}
