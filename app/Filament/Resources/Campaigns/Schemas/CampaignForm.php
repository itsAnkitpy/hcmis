<?php

namespace App\Filament\Resources\Campaigns\Schemas;

use App\Enums\CampaignTemplate;
use App\Filament\Support\CampaignCustomFields;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

/**
 * Campaign create / edit form (M4.C + M4.D). Core fields plus the per-campaign
 * custom-field definitions (FR-LC02) — the lead form renders inputs for these.
 * tenant_id is not a field: it is auto-stamped from the active client context
 * by BelongsToTenant.
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
                CampaignCustomFields::definitionRepeater()
                    ->columnSpanFull(),
            ]);
    }
}
