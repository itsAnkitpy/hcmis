<?php

namespace App\Filament\Resources\Leads\Schemas;

use App\Enums\LeadStatus;
use App\Filament\Support\CampaignCustomFields;
use App\Models\Disposition;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

/**
 * Lead create / edit form (M4.C + M4.D). The campaign and last-disposition
 * options are tenant-scoped automatically (their queries run through the active
 * client's wall). Selecting a campaign reactively renders that campaign's
 * custom fields (FR-LC02) and scopes the disposition list to it (review C1).
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
                    ->preload()
                    // Re-render so the custom fields + disposition list follow the
                    // chosen campaign. Switching campaigns clears stale custom
                    // values (the new campaign defines a different field set).
                    ->live()
                    ->afterStateUpdated(function (Set $set): void {
                        $set('custom_fields', []);
                        $set('last_disposition_id', null);
                    }),
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
                    // C1: only this campaign's own dispositions + the tenant-wide
                    // ones (campaign_id null), never another campaign's outcomes.
                    ->options(fn (Get $get): array => self::dispositionOptions($get('campaign_id')))
                    ->searchable()
                    ->native(false),
                TextInput::make('attempts')
                    ->numeric()
                    ->default(0)
                    ->minValue(0),
                // Reactive per-campaign custom fields (FR-LC02). Always present and
                // keyed; the closure returns no components until a campaign with
                // defined fields is selected, so it renders nothing otherwise.
                Group::make()
                    ->key('leadCustomFields')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema(fn (Get $get): array => CampaignCustomFields::valueFields($get('campaign_id'))),
            ]);
    }

    /**
     * The dispositions selectable for a lead in the given campaign: that
     * campaign's own outcomes plus the tenant-wide ones. Tenant-scoped via RLS.
     * Public so the C1 scoping can be asserted directly in tests.
     *
     * @return array<int, string>
     */
    public static function dispositionOptions(int|string|null $campaignId): array
    {
        return Disposition::query()
            ->when(
                filled($campaignId),
                fn ($query) => $query->where(function ($q) use ($campaignId): void {
                    $q->whereNull('campaign_id')->orWhere('campaign_id', $campaignId);
                }),
                fn ($query) => $query->whereNull('campaign_id'),
            )
            ->orderBy('sort_order')
            ->orderBy('label')
            ->pluck('label', 'id')
            ->all();
    }
}
