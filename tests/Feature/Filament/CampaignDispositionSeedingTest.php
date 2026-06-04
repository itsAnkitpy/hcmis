<?php

use App\Actions\SeedCampaignDispositions;
use App\Enums\CampaignTemplate;
use App\Enums\RoleName;
use App\Filament\Resources\Campaigns\Pages\CreateCampaign;
use App\Models\Campaign;
use App\Models\Disposition;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::resetWebRequest();
    TenantContext::forget();
});

// --- the seeding action (FR-LC06, D-M4E-1/2/3) ---

it('seeds the exact template rows for each campaign template', function (CampaignTemplate $template) {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function () use ($template, $tenant) {
        $campaign = Campaign::factory()->template($template)->create();

        SeedCampaignDispositions::run($campaign);

        /** @var array<int, array<string, mixed>> $expected */
        $expected = config("hcims.disposition_templates.{$template->value}");
        $seeded = $campaign->dispositions()->orderBy('sort_order')->get();

        expect($seeded)->toHaveCount(count($expected));

        foreach ($expected as $index => $row) {
            expect($seeded[$index]->code)->toBe($row['code'])
                ->and($seeded[$index]->label)->toBe($row['label'])
                ->and($seeded[$index]->is_contact)->toBe($row['is_contact'])
                ->and($seeded[$index]->is_sale)->toBe($row['is_sale'])
                ->and($seeded[$index]->sort_order)->toBe($index)
                ->and($seeded[$index]->tenant_id)->toBe($tenant->id)
                ->and($seeded[$index]->campaign_id)->toBe($campaign->id);
        }
    });
})->with(CampaignTemplate::cases());

it('seeds nothing when the template has no config entry', function () {
    config(['hcims.disposition_templates' => []]);

    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function () {
        $campaign = Campaign::factory()->create();

        SeedCampaignDispositions::run($campaign);

        expect($campaign->dispositions()->count())->toBe(0);
    });
});

it('is a no-op when the campaign already has dispositions (seed-once)', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function () {
        $campaign = Campaign::factory()->template(CampaignTemplate::OutboundSales)->create();

        SeedCampaignDispositions::run($campaign);
        $afterFirst = $campaign->dispositions()->count();

        SeedCampaignDispositions::run($campaign);

        expect($campaign->dispositions()->count())->toBe($afterFirst)
            ->and($afterFirst)->toBeGreaterThan(0);
    });
});

// --- the real panel create seam (CreateCampaign::afterCreate) ---

it('seeds dispositions when a campaign is created through the panel', function () {
    $tenant = Tenant::factory()->create();
    $teamLeader = clientUserWithRole($tenant, RoleName::TeamLeader->value);

    test()->actingAs($teamLeader->fresh());
    TenantContext::applyWebRequest($tenant->id, false);

    Livewire::test(CreateCampaign::class)
        ->fillForm([
            'name' => 'Panel Campaign',
            'template' => CampaignTemplate::EdtechEnrollment->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $campaign = Campaign::where('name', 'Panel Campaign')->firstOrFail();

    expect($campaign->dispositions()->count())
        ->toBe(count(config('hcims.disposition_templates.'.CampaignTemplate::EdtechEnrollment->value)));
});

// --- tenant isolation: a campaign's seeded dispositions stay in its tenant ---

it('does not leak seeded dispositions across tenants', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $campaignA = TenantContext::run($tenantA->id, function () {
        $campaign = Campaign::factory()->template(CampaignTemplate::OutboundSales)->create();
        SeedCampaignDispositions::run($campaign);

        return $campaign;
    });

    TenantContext::run($tenantB->id, function () use ($campaignA) {
        expect(Disposition::where('campaign_id', $campaignA->id)->count())->toBe(0);
    });
});
