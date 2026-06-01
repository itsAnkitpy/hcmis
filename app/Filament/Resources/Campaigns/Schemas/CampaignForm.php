<?php

namespace App\Filament\Resources\Campaigns\Schemas;

use App\Enums\CampaignTemplate;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

/**
 * Campaign create / edit form (M4.C). Core fields only — the per-campaign
 * custom-field definitions land in M4.D. tenant_id is not a field: it is
 * auto-stamped from the active client context by BelongsToTenant.
 */
class CampaignForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Select::make('template')
                    ->options(collect(CampaignTemplate::cases())
                        ->mapWithKeys(fn (CampaignTemplate $t): array => [$t->value => $t->label()])
                        ->all())
                    ->required()
                    ->native(false),
                Toggle::make('is_active')
                    ->default(true)
                    ->helperText('Paused campaigns stay visible but are not worked.'),
            ]);
    }
}
