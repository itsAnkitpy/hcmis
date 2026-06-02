<?php

namespace App\Filament\Resources\Scripts\Tables;

use App\Enums\ScriptType;
use App\Filament\Support\ClientColumn;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ScriptsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => ClientColumn::eagerLoad($query))
            ->columns([
                ClientColumn::make(),
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (ScriptType $state): string => $state->label())
                    ->sortable(),
                TextColumn::make('campaign.name')
                    ->label('Campaign')
                    ->placeholder('Tenant-wide')
                    ->toggleable(),
                TextColumn::make('content')
                    ->limit(60)
                    ->wrap(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('type')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
