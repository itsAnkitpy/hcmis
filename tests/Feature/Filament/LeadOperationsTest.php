<?php

use App\Enums\LeadStatus;
use App\Enums\RoleName;
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
 * A team_leader pinned to the client, with the web-path context applied — the
 * exact posture SetCurrentTenant gives client-side staff on a panel request.
 * Returns the tenant so the test can seed under the same context.
 */
function actingAsClientTeamLeader(): Tenant
{
    $tenant = Tenant::factory()->create();
    $teamLeader = clientUserWithRole($tenant, RoleName::TeamLeader->value);

    test()->actingAs($teamLeader->fresh());
    TenantContext::applyWebRequest($tenant->id, false);

    return $tenant;
}

// --- lead filters (FR-LC03): status, attempt-count bucket, age bucket ---

it('filters leads by status, attempt-count bucket, and age bucket', function () {
    actingAsClientTeamLeader();

    $campaign = Campaign::factory()->create();
    $new = Lead::factory()->forCampaign($campaign)->status(LeadStatus::New)->create(['attempts' => 0]);
    $closed = Lead::factory()->forCampaign($campaign)->status(LeadStatus::Closed)->create(['attempts' => 1]);
    $busy = Lead::factory()->forCampaign($campaign)->status(LeadStatus::New)->create(['attempts' => 7]);
    $old = Lead::factory()->forCampaign($campaign)->status(LeadStatus::New)->create(['attempts' => 0]);
    $old->forceFill(['created_at' => now()->subDays(45)])->save();

    Livewire::test(ListLeads::class)
        ->filterTable('status', LeadStatus::Closed->value)
        ->assertCanSeeTableRecords([$closed])
        ->assertCanNotSeeTableRecords([$new, $busy, $old]);

    Livewire::test(ListLeads::class)
        ->filterTable('attempts', ['bucket' => 'high'])
        ->assertCanSeeTableRecords([$busy])
        ->assertCanNotSeeTableRecords([$new, $closed, $old]);

    Livewire::test(ListLeads::class)
        ->filterTable('age', ['bucket' => 'older'])
        ->assertCanSeeTableRecords([$old])
        ->assertCanNotSeeTableRecords([$new, $closed, $busy]);
});

// --- lead movement (FR-LC04): single + bulk reassign campaign_id, same tenant ---

it('moves a single lead to another campaign in the same tenant', function () {
    $tenant = actingAsClientTeamLeader();

    $from = Campaign::factory()->create();
    $to = Campaign::factory()->create();
    $lead = Lead::factory()->forCampaign($from)->create();

    Livewire::test(ListLeads::class)
        ->callTableAction('moveToCampaign', $lead, data: ['campaign_id' => $to->id])
        ->assertHasNoTableActionErrors();

    expect($lead->fresh()->campaign_id)->toBe($to->id)
        ->and($lead->fresh()->tenant_id)->toBe($tenant->id);
});

it('bulk-moves leads to another campaign in the same tenant', function () {
    actingAsClientTeamLeader();

    $from = Campaign::factory()->create();
    $to = Campaign::factory()->create();
    $leads = Lead::factory()->forCampaign($from)->count(3)->create();

    Livewire::test(ListLeads::class)
        ->callTableBulkAction('moveToCampaign', $leads, data: ['campaign_id' => $to->id]);

    $leads->each(fn (Lead $lead) => expect($lead->fresh()->campaign_id)->toBe($to->id));
});

it('rejects moving a lead into a campaign that belongs to another tenant', function () {
    // Seed the foreign campaign first, before pinning to our tenant.
    $other = Tenant::factory()->create();
    $foreign = TenantContext::run($other->id, fn () => Campaign::factory()->create());

    actingAsClientTeamLeader();

    $origin = Campaign::factory()->create();
    $lead = Lead::factory()->forCampaign($origin)->create();

    // The wall hides the other tenant's campaign — the move target is unresolvable.
    expect(Campaign::find($foreign->id))->toBeNull();

    Livewire::test(ListLeads::class)
        ->callTableAction('moveToCampaign', $lead, data: ['campaign_id' => $foreign->id]);

    // Lead stays put — never reassigned across the tenant boundary.
    expect($lead->fresh()->campaign_id)->toBe($origin->id);
});

// --- move-action authorization (D-M4-5 map, review C2) ---

it('hides the move action from a read-only QC user', function () {
    $tenant = Tenant::factory()->create();
    $qc = clientUserWithRole($tenant, RoleName::Qc->value);

    $this->actingAs($qc->fresh());
    TenantContext::applyWebRequest($tenant->id, false);

    $lead = Lead::factory()->forCampaign(Campaign::factory()->create())->create();

    Livewire::test(ListLeads::class)
        ->assertCanSeeTableRecords([$lead])                  // QC reads the list
        ->assertTableActionHidden('moveToCampaign', $lead);  // but cannot move (no update)
});
