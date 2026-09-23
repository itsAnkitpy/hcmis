<?php

use App\Enums\CampaignTemplate;
use App\Enums\ClosedHours;
use App\Enums\RoleName;
use App\Filament\Resources\Tenants\Pages\EditTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    TenantContext::forget();

    $admin = User::factory()->create(['email_verified_at' => now()]);
    Role::findOrCreate(RoleName::SuperAdmin->value, 'web');
    $admin->assignRole(RoleName::SuperAdmin->value);
    $this->actingAs($admin);
});

afterEach(function () {
    TenantContext::forget();
});

it('edits a tenants hours, SLAs, dispositions, and scripts via the Edit page', function () {
    $tenant = Tenant::factory()->create([
        'name' => 'Original Name',
        'settings' => [
            'hours' => [
                'monday' => ['open' => '09:30', 'close' => '18:30'],
                'tuesday' => null,
            ],
            'sla' => [
                'first_attempt_window_min' => 60,
                'max_attempts' => 5,
                'callback_honour_window_hours' => 24,
            ],
            'dispositions' => [
                ['code' => 'OLD', 'label' => 'Old disposition', 'is_contact' => true, 'is_sale' => false],
            ],
            'scripts' => ['opening' => 'old opener'],
            'portal_access' => true,
            'campaign_template' => CampaignTemplate::OutboundSales->value,
        ],
    ]);

    Livewire::test(EditTenant::class, ['record' => $tenant->getRouteKey()])
        ->fillForm([
            'name' => 'Renamed Client',
            'hours' => [
                'monday' => ['is_open' => true, 'open' => '10:00', 'close' => '19:00'],
                'tuesday' => ['is_open' => true, 'open' => '10:00', 'close' => '19:00'],
                'wednesday' => ['is_open' => false],
                'thursday' => ['is_open' => false],
                'friday' => ['is_open' => false],
                'saturday' => ['is_open' => false],
                'sunday' => ['is_open' => false],
            ],
            'sla' => [
                'first_attempt_window_min' => 45,
                'max_attempts' => 8,
                'callback_honour_window_hours' => 36,
            ],
            'dispositions' => [
                ['code' => 'NEW', 'label' => 'New disposition', 'is_contact' => true, 'is_sale' => true],
                ['code' => 'NA', 'label' => 'No answer', 'is_contact' => false, 'is_sale' => false],
            ],
            'scripts' => [
                'opening' => 'fresh opener',
                'objection' => 'fresh objection handling',
                'closing' => '',
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $reloaded = $tenant->fresh();

    expect($reloaded->name)->toBe('Renamed Client')
        ->and($reloaded->settings->hours['monday'])->toBe(['open' => '10:00', 'close' => '19:00'])
        ->and($reloaded->settings->hours['tuesday'])->toBe(['open' => '10:00', 'close' => '19:00'])
        ->and($reloaded->settings->hours['wednesday'])->toBeNull()
        ->and($reloaded->settings->sla)->toBe([
            'first_attempt_window_min' => 45,
            'max_attempts' => 8,
            'callback_honour_window_hours' => 36,
        ])
        ->and($reloaded->settings->dispositions)->toHaveCount(2)
        ->and($reloaded->settings->dispositions[0]['code'])->toBe('NEW')
        ->and($reloaded->settings->scripts)->toBe([
            'opening' => 'fresh opener',
            'objection' => 'fresh objection handling',
        ]);

    // Preserved fields the Edit form doesn't expose.
    expect($reloaded->settings->portalAccess)->toBeTrue()
        ->and($reloaded->settings->campaignTemplate)->toBe(CampaignTemplate::OutboundSales);
});

it('hydrates the Edit form with the current settings (closed days come back as not open)', function () {
    $tenant = Tenant::factory()->create([
        'settings' => [
            'hours' => [
                'monday' => ['open' => '08:00', 'close' => '17:00'],
                'saturday' => null,
                'sunday' => null,
            ],
            'sla' => ['first_attempt_window_min' => 90, 'max_attempts' => 3, 'callback_honour_window_hours' => 12],
            'dispositions' => [['code' => 'X', 'label' => 'X', 'is_contact' => false, 'is_sale' => false]],
            'scripts' => [],
            'portal_access' => false,
            'campaign_template' => CampaignTemplate::Ndr->value,
        ],
    ]);

    Livewire::test(EditTenant::class, ['record' => $tenant->getRouteKey()])
        ->assertFormSet([
            'name' => $tenant->name,
            'hours.monday.is_open' => true,
            'hours.monday.open' => '08:00',
            'hours.monday.close' => '17:00',
            'hours.saturday.is_open' => false,
            'hours.sunday.is_open' => false,
            'sla.max_attempts' => 3,
        ]);
});

it('saves the closed-hours switch and holiday dates, and a date comes back unchanged (inbound-audio slice 1)', function () {
    $tenant = Tenant::factory()->create([
        'settings' => ['dispositions' => [['code' => 'X', 'label' => 'X', 'is_contact' => false, 'is_sale' => false]]],
    ]);

    Livewire::test(EditTenant::class, ['record' => $tenant->getRouteKey()])
        ->fillForm([
            'closed_hours' => ClosedHours::NoPickup->value,
            'holidays' => [['date' => '2026-10-02'], ['date' => '2026-01-26']],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = $tenant->fresh()->settings;

    // 🔴 S118: the panel reads dates in the client's zone (Asia/Kolkata here, 5½ hours
    // ahead of the app clock). A date-only field converted on the way through slips a day.
    expect($settings->closedHours)->toBe(ClosedHours::NoPickup)
        ->and($settings->holidays)->toBe(['2026-01-26', '2026-10-02']);

    Livewire::test(EditTenant::class, ['record' => $tenant->getRouteKey()])
        ->assertFormSet(function (array $state): array {
            expect(array_column($state['holidays'], 'date'))->toBe(['2026-01-26', '2026-10-02']);

            return ['closed_hours' => ClosedHours::NoPickup->value];
        });
});

it('saves the department wait, and a blank one falls back to the default (inbound-audio slice 7)', function () {
    $tenant = Tenant::factory()->create([
        'settings' => ['dispositions' => [['code' => 'X', 'label' => 'X', 'is_contact' => false, 'is_sale' => false]]],
    ]);

    Livewire::test(EditTenant::class, ['record' => $tenant->getRouteKey()])
        ->fillForm(['department_wait_seconds' => 45])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($tenant->fresh()->departmentWaitSeconds())->toBe(45);

    Livewire::test(EditTenant::class, ['record' => $tenant->getRouteKey()])
        ->fillForm(['department_wait_seconds' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($tenant->fresh()->department_wait_seconds)->toBeNull()
        ->and($tenant->fresh()->departmentWaitSeconds())->toBe(60);
});
