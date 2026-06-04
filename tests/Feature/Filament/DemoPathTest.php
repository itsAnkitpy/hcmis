<?php

use App\Enums\CampaignTemplate;
use App\Enums\LeadStatus;
use App\Enums\RoleName;
use App\Filament\Resources\Campaigns\Pages\CreateCampaign;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::resetWebRequest();
    TenantContext::forget();
});

/**
 * The Phase-1 exit proof (D-M4E-5): a client-scoped team_leader walks the whole
 * admin loop — create a campaign (dispositions auto-seed), load leads, filter,
 * move, mark a disposition — all under the tenant wall.
 */
it('walks the phase-1 demo path end to end, tenant-scoped', function () {
    $tenant = Tenant::factory()->create();
    $teamLeader = clientUserWithRole($tenant, RoleName::TeamLeader->value);
    test()->actingAs($teamLeader->fresh());
    TenantContext::applyWebRequest($tenant->id, false);

    // 1. create a campaign through the panel → its dispositions seed from the
    //    template (FR-LC06).
    Livewire::test(CreateCampaign::class)
        ->fillForm(['name' => 'Spring Drive', 'template' => CampaignTemplate::OutboundSales->value])
        ->call('create')
        ->assertHasNoFormErrors();

    $campaign = Campaign::where('name', 'Spring Drive')->firstOrFail();
    expect($campaign->dispositions()->count())
        ->toBe(count(config('hcims.disposition_templates.'.CampaignTemplate::OutboundSales->value)));

    // a second campaign to move a lead into
    $other = Campaign::factory()->template(CampaignTemplate::CustomerCareCallback)->create();

    // 2. load leads with a spread.
    $fresh = Lead::factory()->forCampaign($campaign)->status(LeadStatus::New)->create(['attempts' => 0]);
    $closed = Lead::factory()->forCampaign($campaign)->status(LeadStatus::Closed)->create(['attempts' => 8]);

    // 3. a filter narrows the list.
    Livewire::test(ListLeads::class)
        ->filterTable('status', LeadStatus::Closed->value)
        ->assertCanSeeTableRecords([$closed])
        ->assertCanNotSeeTableRecords([$fresh]);

    // 4. move a lead (FR-LC04) — campaign changes, tenant does not.
    Livewire::test(ListLeads::class)
        ->callTableAction('moveToCampaign', $fresh, data: ['campaign_id' => $other->id])
        ->assertHasNoTableActionErrors();

    expect($fresh->fresh()->campaign_id)->toBe($other->id)
        ->and($fresh->fresh()->tenant_id)->toBe($tenant->id);

    // 5. mark a disposition (data-level, D-M4-3): point the lead at an outcome.
    $sold = $campaign->dispositions()->where('code', 'SOLD')->firstOrFail();
    $closed->update(['last_disposition_id' => $sold->id]);

    expect($closed->fresh()->last_disposition_id)->toBe($sold->id);
});
