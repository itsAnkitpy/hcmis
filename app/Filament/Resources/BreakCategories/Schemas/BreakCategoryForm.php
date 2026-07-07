<?php

namespace App\Filament\Resources\BreakCategories\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

/**
 * Break category create / edit form (BK-1). No delete anywhere — categories
 * are switched off instead, so history rows keep pointing at a real category.
 */
class BreakCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->required()
                    ->maxLength(40)
                    ->helperText('Short uppercase code, e.g. LUNCH_BREAK. Stays stable; the label is what agents see.'),
                TextInput::make('label')
                    ->required()
                    ->maxLength(60),
                TextInput::make('time_limit_minutes')
                    ->label('Time limit (minutes)')
                    ->numeric()
                    ->minValue(1)
                    ->helperText('Leave empty for no limit — the agent sees elapsed time only, never an overstay alert.'),
                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true)
                    ->helperText('Off hides it from the agent picker. Categories are never deleted — history keeps pointing at them.'),
                TextInput::make('sort_order')
                    ->numeric()
                    ->default(0)
                    ->minValue(0),
            ]);
    }
}
