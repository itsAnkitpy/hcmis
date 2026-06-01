<?php

namespace App\Filament\Resources\Scripts\Schemas;

use App\Enums\ScriptType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

/**
 * Script create / edit form (M4.C). campaign_id optional: empty = tenant-wide
 * script, a value scopes it to one campaign (D-M4-2).
 */
class ScriptForm
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
                    ->placeholder('All campaigns (tenant-wide)'),
                Select::make('type')
                    ->options(collect(ScriptType::cases())
                        ->mapWithKeys(fn (ScriptType $t): array => [$t->value => $t->label()])
                        ->all())
                    ->required()
                    ->native(false),
                Textarea::make('content')
                    ->required()
                    ->rows(6),
            ]);
    }
}
