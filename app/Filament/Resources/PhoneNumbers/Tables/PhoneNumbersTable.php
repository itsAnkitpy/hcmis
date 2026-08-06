<?php

namespace App\Filament\Resources\PhoneNumbers\Tables;

use App\Filament\Support\ClientColumn;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PhoneNumbersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => ClientColumn::eagerLoad($query))
            ->columns([
                ClientColumn::make(),
                TextColumn::make('number')
                    ->label('Phone number')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('campaign.name')
                    ->label('Campaign')
                    ->placeholder('No campaign')
                    ->searchable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),
            ])
            ->defaultSort('number')
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
            ])
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
