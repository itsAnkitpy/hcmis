<?php

namespace App\Filament\Resources\Tenants\Tables;

use App\Enums\TenantStatus;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TenantsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (TenantStatus $state): string => $state->label())
                    ->color(fn (TenantStatus $state): string => match ($state) {
                        TenantStatus::Active => 'success',
                        TenantStatus::Suspended => 'warning',
                        TenantStatus::Archived => 'gray',
                    }),
                TextColumn::make('suspended_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('archived_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(TenantStatus::cases())->mapWithKeys(
                        fn (TenantStatus $s): array => [$s->value => $s->label()]
                    )->all()),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                // Archival via the EditTenant header action is the supported
                // removal path — bulk delete is intentionally not exposed.
            ]);
    }
}
