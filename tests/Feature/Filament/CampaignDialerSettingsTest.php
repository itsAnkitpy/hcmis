<?php

use App\Enums\CampaignCategory;
use App\Enums\CampaignTemplate;
use App\Enums\DialMode;
use App\Enums\RoleName;
use App\Filament\Resources\Campaigns\Pages\CreateCampaign;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::resetWebRequest();
    TenantContext::forget();
});

/**
 * DIAL-1 slice 2 — the campaign's dialer settings (DP-4 … DP-6a).
 *
 * The promise of DP-4 is that adding seven columns changes the behaviour of
 * nothing: a campaign created the way every existing one was created still
 * reads `manual`, still is not dialing, and still has no attempt cap. If that
 * ever goes red, the migration silently switched a live floor to auto-dialing.
 */
it('leaves a campaign made the old way on manual, not dialing, inside the legal window', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function () {
        // No dialer settings passed — exactly how every campaign on the floor was made.
        $campaign = Campaign::factory()->create()->fresh();

        expect($campaign->dial_mode)->toBe(DialMode::Manual)
            ->and($campaign->is_dialing)->toBeFalse()
            ->and($campaign->category)->toBe(CampaignCategory::Promotional)
            ->and($campaign->caller_id)->toBeNull()
            // Wall-clock strings, deliberately uncast (S118). Postgres spells them HH:MM:SS.
            ->and($campaign->dial_start_time)->toBe('10:00:00')
            ->and($campaign->dial_end_time)->toBe('21:00:00')
            ->and($campaign->max_attempts)->toBeNull();
    });
});

// --- DP-5: the fields on the form ---

it('saves a progressive campaign through the panel with its window unshifted', function () {
    $tenant = Tenant::factory()->create();
    $teamLeader = clientUserWithRole($tenant, RoleName::TeamLeader->value);

    test()->actingAs($teamLeader->fresh());
    TenantContext::applyWebRequest($tenant->id, false);

    Livewire::test(CreateCampaign::class)
        ->fillForm([
            'name' => 'Night Support',
            'template' => CampaignTemplate::CustomerCareCallback->value,
            'dial_mode' => DialMode::Progressive->value,
            'is_dialing' => true,
            'category' => CampaignCategory::Service->value,
            'caller_id' => '+911601234567',
            'dial_start_time' => '08:00',
            'dial_end_time' => '23:00',
            'max_attempts' => 4,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $campaign = Campaign::where('name', 'Night Support')->firstOrFail();

    expect($campaign->dial_mode)->toBe(DialMode::Progressive)
        ->and($campaign->is_dialing)->toBeTrue()
        ->and($campaign->category)->toBe(CampaignCategory::Service)
        ->and($campaign->caller_id)->toBe('+911601234567')
        // 🔴 The S118 assertion. 08:00 typed must be 08:00 stored. If the picker ever
        // loses its ->timezone() pin, the panel's reading zone shifts these by 5h30m
        // and a campaign silently calls at the wrong hours.
        ->and($campaign->dial_start_time)->toBe('08:00:00')
        ->and($campaign->dial_end_time)->toBe('23:00:00')
        ->and($campaign->max_attempts)->toBe(4);
});

it('pre-fills the legal window so a campaign made without touching Dialing is compliant', function () {
    $tenant = Tenant::factory()->create();
    $teamLeader = clientUserWithRole($tenant, RoleName::TeamLeader->value);

    test()->actingAs($teamLeader->fresh());
    TenantContext::applyWebRequest($tenant->id, false);

    // Name and template only — the way the panel was used before slice 2 existed.
    Livewire::test(CreateCampaign::class)
        ->fillForm([
            'name' => 'Plain Campaign',
            'template' => CampaignTemplate::OutboundSales->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $campaign = Campaign::where('name', 'Plain Campaign')->firstOrFail();

    expect($campaign->dial_mode)->toBe(DialMode::Manual)
        ->and($campaign->is_dialing)->toBeFalse()
        ->and($campaign->dial_start_time)->toBe('10:00:00')
        ->and($campaign->dial_end_time)->toBe('21:00:00');
});

// --- DP-6: the attempt cap in the serving rule ---

it('stops serving a lead that has hit its campaign attempt cap, and serves it again if the cap is raised', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function () {
        $campaign = Campaign::factory()->create(['max_attempts' => 3]);

        $tried = Lead::factory()->for($campaign)->create(['attempts' => 3]);
        $fresh = Lead::factory()->for($campaign)->create(['attempts' => 0]);

        $servable = Lead::callable($campaign->id)->pluck('id');

        expect($servable)->toContain($fresh->id)
            ->and($servable)->not->toContain($tried->id);

        // The cap is read live off the campaign, not copied onto the lead, so raising
        // it returns capped-out leads with no backfill job.
        $campaign->update(['max_attempts' => 5]);

        expect(Lead::callable($campaign->id)->pluck('id'))->toContain($tried->id);
    });
});

it('keeps serving a worn-out lead when the campaign has no cap', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function () {
        // No max_attempts — every campaign on the floor today.
        $campaign = Campaign::factory()->create();
        $tried = Lead::factory()->for($campaign)->create(['attempts' => 40]);

        expect(Lead::callable($campaign->id)->pluck('id'))->toContain($tried->id);
    });
});

// --- DP-6a: the compliance validators (G2, G3) and the F5 refusal ---

/**
 * Drive the panel's create form with one campaign's worth of dialer settings.
 *
 * @param  array<string, mixed>  $dialer
 */
function createCampaignWith(Tenant $tenant, array $dialer): Testable
{
    $teamLeader = clientUserWithRole($tenant, RoleName::TeamLeader->value);

    test()->actingAs($teamLeader->fresh());
    TenantContext::applyWebRequest($tenant->id, false);

    return Livewire::test(CreateCampaign::class)
        ->fillForm([
            'name' => 'Validator '.fake()->unique()->word(),
            'template' => CampaignTemplate::OutboundSales->value,
            ...$dialer,
        ])
        ->call('create');
}

it('refuses a marketing campaign that starts before 10:00', function () {
    createCampaignWith(Tenant::factory()->create(), [
        'category' => CampaignCategory::Promotional->value,
        'dial_start_time' => '08:00',
        'dial_end_time' => '21:00',
    ])->assertHasFormErrors(['dial_start_time']);
});

it('refuses a marketing campaign that runs past 21:00', function () {
    createCampaignWith(Tenant::factory()->create(), [
        'category' => CampaignCategory::Promotional->value,
        'dial_start_time' => '10:00',
        'dial_end_time' => '22:30',
    ])->assertHasFormErrors(['dial_end_time']);
});

it('accepts the same 08:00 start for a support campaign, because time bands do not apply', function () {
    createCampaignWith(Tenant::factory()->create(), [
        'category' => CampaignCategory::Service->value,
        'dial_start_time' => '08:00',
        'dial_end_time' => '23:00',
    ])->assertHasNoFormErrors();
});

it('refuses a progressive campaign with no caller ID, and accepts it with one', function () {
    $tenant = Tenant::factory()->create();

    createCampaignWith($tenant, [
        'dial_mode' => DialMode::Progressive->value,
        'caller_id' => null,
    ])->assertHasFormErrors(['caller_id']);

    createCampaignWith($tenant, [
        'dial_mode' => DialMode::Progressive->value,
        'caller_id' => '+911400000000',
    ])->assertHasNoFormErrors();
});

it('lets a manual campaign save with no caller ID', function () {
    createCampaignWith(Tenant::factory()->create(), [
        'dial_mode' => DialMode::Manual->value,
        'caller_id' => null,
    ])->assertHasNoFormErrors();
});

it('refuses a window that ends before it starts instead of saving one that never dials', function () {
    // F5. A night shift would want 21:00 to 06:00; slice 3 asks "is now between the
    // two" and would match nothing, with no error anywhere. Refused, not supported.
    createCampaignWith(Tenant::factory()->create(), [
        'category' => CampaignCategory::Service->value,
        'dial_start_time' => '21:00',
        'dial_end_time' => '06:00',
    ])->assertHasFormErrors(['dial_end_time']);
});
