<?php

namespace App\Filament\Resources\Campaigns\Pages;

use App\Actions\SeedCampaignDispositions;
use App\Filament\Resources\Campaigns\CampaignResource;
use App\Models\Campaign;
use Filament\Resources\Pages\CreateRecord;

class CreateCampaign extends CreateRecord
{
    protected static string $resource = CampaignResource::class;

    /**
     * Seed the campaign's starter dispositions from its template (FR-LC06).
     * The panel is the only real create path in Phase 1; the demo seeder calls
     * the same action so the copy logic lives in exactly one place.
     */
    protected function afterCreate(): void
    {
        /** @var Campaign $campaign */
        $campaign = $this->record;

        SeedCampaignDispositions::run($campaign);
    }
}
