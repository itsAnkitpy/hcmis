<?php

namespace App\Filament\Resources\Dispositions\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

/**
 * Disposition create / edit form (M4.C). campaign_id is optional: empty means a
 * tenant-wide outcome set, a value scopes the outcome to one campaign (D-M4-2).
 */
class DispositionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('campaign_id')
                    ->label('Campaign')
                    ->relationship('campaign', 'name')
                    ->searchable()
                    ->preload()
                    ->placeholder('All campaigns (tenant-wide)')
                    ->helperText('Leave empty for a tenant-wide outcome.'),
                TextInput::make('code')
                    ->required()
                    ->maxLength(40),
                TextInput::make('label')
                    ->required()
                    ->maxLength(60),
                Toggle::make('is_contact')
                    ->helperText('We actually spoke to a human.'),
                Toggle::make('is_sale')
                    ->helperText('Ended in a positive business outcome.'),
                TextInput::make('sort_order')
                    ->numeric()
                    ->default(0)
                    ->minValue(0),
            ]);
    }
}
