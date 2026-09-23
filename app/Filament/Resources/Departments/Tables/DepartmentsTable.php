<?php

namespace App\Filament\Resources\Departments\Tables;

use App\Filament\Support\ClientColumn;
use App\Models\Department;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * No bulk delete: a department any call points at cannot be deleted (D9), and a bulk
 * delete would hit that refusal as an error page. Delete is per row, and shown only
 * for a department no call ever used.
 */
class DepartmentsTable
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
                TextColumn::make('members_count')
                    ->label('Agents')
                    ->counts('members'),
                IconColumn::make('is_active')
                    ->label('Switched on')
                    ->boolean(),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->hidden(fn (Department $record): bool => $record->isInUse()),
            ]);
    }
}
