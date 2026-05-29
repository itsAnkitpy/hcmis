<?php

use App\Enums\CampaignTemplate;
use App\Models\Tenant;
use App\Tenancy\Settings\TenantSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns a default settings DTO when the column is null', function () {
    $tenant = Tenant::factory()->create(['settings' => null]);

    $settings = $tenant->fresh()->settings;

    expect($settings)->toBeInstanceOf(TenantSettings::class)
        ->and($settings->portalAccess)->toBeFalse()
        ->and($settings->campaignTemplate)->toBeNull()
        ->and($settings->sla['max_attempts'])->toBe(5);
});

it('round-trips a DTO through the cast', function () {
    $tenant = Tenant::factory()->create();

    $tenant->settings = new TenantSettings(
        hours: ['monday' => ['open' => '09:30', 'close' => '18:30']],
        sla: [
            'first_attempt_window_min' => 30,
            'max_attempts' => 7,
            'callback_honour_window_hours' => 12,
        ],
        dispositions: [
            ['code' => 'CONTACTED', 'label' => 'Contacted', 'is_contact' => true, 'is_sale' => false],
        ],
        scripts: ['opening' => 'Hi, this is HC calling.'],
        portalAccess: true,
        campaignTemplate: CampaignTemplate::OutboundSales,
    );
    $tenant->save();

    $reloaded = $tenant->fresh()->settings;

    expect($reloaded)->toBeInstanceOf(TenantSettings::class)
        ->and($reloaded->hours['monday']['open'])->toBe('09:30')
        ->and($reloaded->sla['max_attempts'])->toBe(7)
        ->and($reloaded->dispositions[0]['code'])->toBe('CONTACTED')
        ->and($reloaded->scripts['opening'])->toBe('Hi, this is HC calling.')
        ->and($reloaded->portalAccess)->toBeTrue()
        ->and($reloaded->campaignTemplate)->toBe(CampaignTemplate::OutboundSales);
});

it('accepts a plain array on assignment', function () {
    $tenant = Tenant::factory()->create();

    $tenant->settings = [
        'portal_access' => true,
        'campaign_template' => CampaignTemplate::Ndr->value,
    ];
    $tenant->save();

    $reloaded = $tenant->fresh()->settings;

    expect($reloaded->portalAccess)->toBeTrue()
        ->and($reloaded->campaignTemplate)->toBe(CampaignTemplate::Ndr);
});
