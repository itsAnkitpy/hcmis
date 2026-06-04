<?php

namespace App\Actions;

use App\Models\Campaign;

/**
 * Copy a campaign's starter disposition set from the config templates into real
 * `dispositions` rows when the campaign is created (FR-LC06, decision D-M4E-1).
 *
 * Called from the two real campaign-create seams in Phase 1: the Filament
 * CreateCampaign page and the DemoDataSeeder. Kept as one explicit unit so
 * neither seam duplicates the copy logic, and so it relocates cleanly into a
 * Campaign observer the day an API/import create path appears.
 *
 * Behaviour:
 *  - per-campaign (D-M4E-3): rows carry the campaign's id; tenant_id is
 *    auto-stamped by BelongsToTenant from the active TenantContext.
 *  - seed-once (D-M4E-2): a campaign that already has ≥1 disposition is left
 *    untouched, so editing a template later never retro-changes existing
 *    campaigns and the demo seeder is safe to re-run.
 *  - a template with no config entry seeds nothing, no error.
 */
class SeedCampaignDispositions
{
    /**
     * @phpstan-type DispositionTemplateRow array{code: string, label: string, is_contact: bool, is_sale: bool}
     */
    public function __invoke(Campaign $campaign): void
    {
        if ($campaign->dispositions()->exists()) {
            return;
        }

        /** @var array<int, DispositionTemplateRow> $rows */
        $rows = config("hcims.disposition_templates.{$campaign->template->value}", []);

        foreach ($rows as $index => $row) {
            $campaign->dispositions()->create([
                'code' => $row['code'],
                'label' => $row['label'],
                'is_contact' => $row['is_contact'],
                'is_sale' => $row['is_sale'],
                'sort_order' => $index,
            ]);
        }
    }

    /**
     * Convenience callable for use as an action target.
     */
    public static function run(Campaign $campaign): void
    {
        (new self)($campaign);
    }
}
