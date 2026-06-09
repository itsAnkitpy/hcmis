<?php

namespace App\Filament\Resources\ActivityLogs\Tables;

use App\Filament\Support\ClientColumn;
use App\Models\ActivityLog;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
            ])
            ->toolbarActions([]);
    }
}
