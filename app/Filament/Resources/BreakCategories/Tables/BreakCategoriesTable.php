<?php

namespace App\Filament\Resources\BreakCategories\Tables;

use App\Filament\Support\ClientColumn;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * No delete actions by design (BK-1): categories are deactivated, never
 * deleted, so status-history rows always resolve to a real category.
 */
class BreakCategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => ClientColumn::eagerLoad($query))
            ->columns([
                ClientColumn::make(),
                TextColumn::make('code')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('label')
                    ->searchable(),
                TextColumn::make('time_limit_minutes')
                    ->label('Limit (min)')
                    ->placeholder('No limit')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('sort_order')
                    ->label('Order')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort_order')
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
