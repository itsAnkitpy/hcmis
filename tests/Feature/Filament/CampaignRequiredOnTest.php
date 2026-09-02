<?php

use App\Enums\RoleName;
use App\Filament\Resources\Campaigns\Pages\CreateCampaign;
use App\Filament\Resources\Campaigns\Pages\EditCampaign;
use App\Filament\Resources\Leads\Schemas\LeadForm;
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

/**
 * CP-10 (disposition-driven-fields.md) — the campaign screen half. A team leader ticks
 * the outcomes a must-fill box is actually required for, so "Customer informed" stops
 * asking for a policy number nobody needed.
 *
 * A team_leader pinned to the client mirrors the real SetCurrentTenant flow, and
 * Livewire::test must NOT be wrapped in TenantContext::run() — same rule as
 * LeadCustomFieldsTest.
 */
function campaignScreenFor(Tenant $tenant, bool $mustFill): Campaign
{
    $teamLeader = clientUserWithRole($tenant, RoleName::TeamLeader->value);

    test()->actingAs($teamLeader->fresh());
    TenantContext::applyWebRequest($tenant->id, false);

    $campaign = Campaign::factory()->withCustomFields([
        ['key' => 'policy_number', 'label' => 'Policy Number', 'type' => 'text', 'required' => $mustFill],
    ])->create();

    Disposition::factory()->forCampaign($campaign)->create(['is_contact' => true, 'label' => 'Issue resolved']);
    Disposition::factory()->forCampaign($campaign)->create(['is_contact' => false, 'label' => 'Voicemail left']);

    return $campaign;
}

// T11 — DF-3. The list has no meaning on a box that blocks nothing, so it stays hidden
// until Must fill is on. Most boxes are not must-fill and stay exactly as simple as they
// are today.
it('hides the outcome checklist while must fill is off', function () {
    $campaign = campaignScreenFor(Tenant::factory()->create(), mustFill: false);

    Livewire::test(EditCampaign::class, ['record' => $campaign->getRouteKey()])
        ->assertDontSee('Required only for these outcomes');
});

it('shows the outcome checklist once must fill is on', function () {
    $campaign = campaignScreenFor(Tenant::factory()->create(), mustFill: true);

    Livewire::test(EditCampaign::class, ['record' => $campaign->getRouteKey()])
        ->assertSee('Required only for these outcomes');
});

// T10 — DF-4. Only outcomes where the agent reached a person are offered. A tick beside
// "Voicemail left" would do nothing at all, because the is_contact gate in AgentConsole
// never lets the must-fill check run on one, and a control that lies about what it does
// is worse than a missing one.
it('offers contact outcomes only, and says why the others are missing', function () {
    $campaign = campaignScreenFor(Tenant::factory()->create(), mustFill: true);

    Livewire::test(EditCampaign::class, ['record' => $campaign->getRouteKey()])
        ->assertSee('Issue resolved')
        ->assertDontSee('Voicemail left')
        // The helper block, which is the only place DF-4, DF-7 and DF-8 are explained.
        ->assertSee('Only outcomes where the agent reached a person can require a box.')
        ->assertSee('to stop requiring it, turn off Must fill above', escape: false)
        ->assertSee('Office staff filling in the lead form are always asked for it')
        // 🔴 The helper text above names a control by name, so that control has to exist
        // by that name — it did not on the first build, where the toggle carried
        // Filament's generated "Required". Order is what makes this exact: the helper
        // text's own "Must fill" sits INSIDE the sentence starting "Only outcomes…", so
        // an earlier one can only be the toggle's label.
        ->assertSeeInOrder(['Must fill', 'Only outcomes where the agent reached a person']);
});

// The same rule at its source, asserted by identity rather than by count: the checklist
// calls dispositionOptions(contactOnly: true), and that is what filters the list.
it('filters the outcome list to contact outcomes at the query', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function (): void {
        $campaign = Campaign::factory()->create();
        Disposition::factory()->forCampaign($campaign)->create(['is_contact' => true, 'label' => 'Issue resolved']);
        Disposition::factory()->forCampaign($campaign)->create(['is_contact' => true, 'label' => 'Customer informed']);
        Disposition::factory()->forCampaign($campaign)->create(['is_contact' => false, 'label' => 'No answer']);

        expect(array_values(LeadForm::dispositionOptions($campaign->id, contactOnly: true)))
            ->toEqualCanonicalizing(['Issue resolved', 'Customer informed'])
            // The unfiltered list is unchanged — the lead form still offers all three.
            ->and(array_values(LeadForm::dispositionOptions($campaign->id)))
            ->toEqualCanonicalizing(['Issue resolved', 'Customer informed', 'No answer']);
    });
});

// DF-5 — CreateCampaign seeds a campaign's outcomes in afterCreate(), so while the create
// form is on screen there are none of its own. The checklist would render empty and read
// as a bug, so a line of text stands in its place. DF-1 makes the wait safe: a box saved
// with nothing ticked blocks on every contact outcome, which is today's behaviour.
it('offers a line of text instead of the checklist on the create screen', function () {
    $tenant = Tenant::factory()->create();
    $teamLeader = clientUserWithRole($tenant, RoleName::TeamLeader->value);

    $this->actingAs($teamLeader->fresh());
    TenantContext::applyWebRequest($tenant->id, false);

    Livewire::test(CreateCampaign::class)
        ->fillForm([
            'custom_fields' => [
                ['key' => 'policy_number', 'label' => 'Policy Number', 'type' => 'text', 'required' => true],
            ],
        ])
        ->assertSee('Outcomes are created with the campaign. Save, then reopen this box to choose them.');
});

// The round trip. The ids the leader ticks are what the console's check reads back, so a
// stored list that came out of this screen has to be the same shape T6 pins.
it('stores the ticked outcome ids on the box', function () {
    $tenant = Tenant::factory()->create();
    $campaign = campaignScreenFor($tenant, mustFill: true);

    $resolvedId = TenantContext::run($tenant->id, fn (): int => Disposition::query()
        ->where('label', 'Issue resolved')->sole()->id);

    Livewire::test(EditCampaign::class, ['record' => $campaign->getRouteKey()])
        ->fillForm([
            'custom_fields' => [
                ['key' => 'policy_number', 'label' => 'Policy Number', 'type' => 'text', 'required' => true,
                    'required_on' => [$resolvedId]],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($campaign->fresh()->custom_fields[0]['required_on'])->toBe([$resolvedId]);
});

// DF-3's second half, and the one the round trip above does not reach. Filament drops a
// hidden field from the saved state, so unticking Must fill would take the stored ids
// with it — and re-ticking would hand the leader an empty list, which DF-1 reads as
// "block on every contact outcome". A mis-click must cost nothing.
it('keeps the ticked outcome ids when must fill is turned off', function () {
    $tenant = Tenant::factory()->create();
    $campaign = campaignScreenFor($tenant, mustFill: true);

    $resolvedId = TenantContext::run($tenant->id, fn (): int => Disposition::query()
        ->where('label', 'Issue resolved')->sole()->id);

    TenantContext::run($tenant->id, fn () => $campaign->update(['custom_fields' => [
        ['key' => 'policy_number', 'label' => 'Policy Number', 'type' => 'text', 'required' => true,
            'required_on' => [$resolvedId]],
    ]]));

    Livewire::test(EditCampaign::class, ['record' => $campaign->getRouteKey()])
        ->fillForm([
            'custom_fields' => [
                ['key' => 'policy_number', 'label' => 'Policy Number', 'type' => 'text', 'required' => false,
                    'required_on' => [$resolvedId]],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($campaign->fresh()->custom_fields[0])
        ->toHaveKey('required_on', [$resolvedId])
        ->toHaveKey('required', false);
});

// DF-5 said to confirm rather than assume that a repeater item's ->options() closure gets
// the CAMPAIGN as $record. The T10 test above passes either way — a tenant-wide list would
// also show "Issue resolved" and hide "Voicemail left" — so the scoping needs its own
// assertion. A sibling campaign's outcome must never appear in this campaign's checklist.
it('offers this campaign outcomes only, not another campaign in the same client', function () {
    $tenant = Tenant::factory()->create();
    $campaign = campaignScreenFor($tenant, mustFill: true);

    TenantContext::run($tenant->id, function (): void {
        Disposition::factory()->forCampaign(Campaign::factory()->create())
            ->create(['is_contact' => true, 'label' => 'Zebra outcome']);
    });

    Livewire::test(EditCampaign::class, ['record' => $campaign->getRouteKey()])
        ->assertSee('Issue resolved')
        ->assertDontSee('Zebra outcome');
});
