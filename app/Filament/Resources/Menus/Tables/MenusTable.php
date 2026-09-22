<?php

namespace App\Filament\Resources\Menus\Tables;

use App\Filament\Support\ClientColumn;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MenusTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => ClientColumn::eagerLoad($query))
            ->columns([
                ClientColumn::make(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('options')
                    ->label('Keys')
                    ->state(fn ($record): string => collect($record->options ?? [])
                        ->map(fn (array $option): string => (string) ($option['key'] ?? '?'))
                        ->join(', '))
                    ->placeholder('No keys yet'),
                TextColumn::make('phone_numbers_count')
                    ->label('Numbers using it')
                    ->counts('phoneNumbers'),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
                // Deleting is offered here and not hidden behind a bulk action, because
                // it is also what takes a menu's sounds off the disk (Menu::booted) —
                // and those addresses never expire. Numbers pointing at a deleted menu
                // fall back to today's behaviour rather than breaking.
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
