<?php

namespace App\Filament\Resources\Leads\Schemas;

use App\Enums\LeadStatus;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * Lead create / edit form (M4.C). Core fields only — custom-field values,
 * filters and the "move to campaign" action are M4.D. The campaign and
 * last-disposition options are tenant-scoped automatically (the relationship
 * queries run through the active client's wall).
 */
class LeadForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('campaign_id')
                    ->relationship('campaign', 'name')
                    ->required()
                    ->searchable()
                    ->preload(),
                TextInput::make('name')
                    ->maxLength(255),
                TextInput::make('phone')
                    ->tel()
                    ->required()
                    ->maxLength(40),
                TextInput::make('email')
                    ->email()
                    ->maxLength(255),
                TextInput::make('region')
                    ->maxLength(120),
                Select::make('status')
                    ->options(collect(LeadStatus::cases())
                        ->mapWithKeys(fn (LeadStatus $s): array => [$s->value => $s->label()])
                        ->all())
                    ->default(LeadStatus::New->value)
                    ->required()
                    ->native(false),
                Select::make('last_disposition_id')
                    ->label('Last disposition')
                    ->relationship('lastDisposition', 'label')
                    ->searchable()
                    ->preload(),
                TextInput::make('attempts')
                    ->numeric()
                    ->default(0)
                    ->minValue(0),
            ]);
    }
}
