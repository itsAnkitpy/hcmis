<?php

use App\Enums\CampaignTemplate;
use App\Enums\RoleName;
use App\Enums\TenantStatus;
use App\Filament\Resources\Tenants\Pages\CreateTenant;
use App\Filament\Resources\Tenants\Pages\EditTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    TenantContext::forget();

    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    Role::findOrCreate(RoleName::SuperAdmin->value, 'web');
    $this->admin->assignRole(RoleName::SuperAdmin->value);
    $this->actingAs($this->admin);
});

afterEach(function () {
    TenantContext::forget();
});

it('creates a tenant via the wizard, provisions per-tenant roles, and attaches agents with the right team', function () {
    Livewire::test(CreateTenant::class)
        ->fillForm([
            'name' => 'PW Acquisition',
            'slug' => 'pw-acquisition',
            'campaign_template' => CampaignTemplate::OutboundSales->value,
            'sla' => [
                'first_attempt_window_min' => 30,
                'max_attempts' => 7,
                'callback_honour_window_hours' => 12,
            ],
            'hours' => [
                'monday' => ['is_open' => true, 'open' => '09:30', 'close' => '18:30'],
                'tuesday' => ['is_open' => true, 'open' => '09:30', 'close' => '18:30'],
                'wednesday' => ['is_open' => true, 'open' => '09:30', 'close' => '18:30'],
                'thursday' => ['is_open' => true, 'open' => '09:30', 'close' => '18:30'],
                'friday' => ['is_open' => true, 'open' => '09:30', 'close' => '18:30'],
                'saturday' => ['is_open' => false],
                'sunday' => ['is_open' => false],
            ],
            'dispositions' => [
                ['code' => 'INTERESTED', 'label' => 'Interested', 'is_contact' => true, 'is_sale' => false],
                ['code' => 'SOLD', 'label' => 'Sold', 'is_contact' => true, 'is_sale' => true],
            ],
            'scripts' => [
                'opening' => 'Hi, this is HC calling.',
                'objection' => 'I understand. May I just share...',
                'closing' => 'Thank you for your time.',
            ],
            'agents' => [
                ['name' => 'Riya Sharma', 'email' => 'riya@example.test', 'role_name' => RoleName::TeamLeader->value],
                ['name' => 'Arjun Patel', 'email' => 'arjun@example.test', 'role_name' => RoleName::Agent->value],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $tenant = Tenant::query()->where('slug', 'pw-acquisition')->firstOrFail();

    expect($tenant->status)->toBe(TenantStatus::Active)
        ->and($tenant->settings->campaignTemplate)->toBe(CampaignTemplate::OutboundSales)
        ->and($tenant->settings->sla['max_attempts'])->toBe(7)
        ->and($tenant->settings->hours['monday']['open'])->toBe('09:30')
        ->and($tenant->settings->hours['saturday'])->toBeNull()
        ->and($tenant->settings->scripts['opening'])->toBe('Hi, this is HC calling.')
        ->and($tenant->settings->dispositions)->toHaveCount(2)
        ->and($tenant->settings->portalAccess)->toBeFalse();

    // The 5 per-client roles auto-provisioned in this tenant's team.
    $rolesForTenant = Role::query()->where('team_id', $tenant->getKey())->pluck('name')->sort()->values()->all();
    expect($rolesForTenant)->toBe(collect(RoleName::perClientValues())->sort()->values()->all());

    // Agents attached + roles assigned in the new tenant's team — not team 0.
    $riya = User::query()->where('email', 'riya@example.test')->firstOrFail();
    $arjun = User::query()->where('email', 'arjun@example.test')->firstOrFail();

    expect($riya->tenants()->whereKey($tenant->getKey())->exists())->toBeTrue()
        ->and($arjun->tenants()->whereKey($tenant->getKey())->exists())->toBeTrue();

    TenantContext::run($tenant->getKey(), function () use ($riya, $arjun) {
        expect($riya->fresh()->hasRole(RoleName::TeamLeader->value))->toBeTrue()
            ->and($arjun->fresh()->hasRole(RoleName::Agent->value))->toBeTrue();
    });

    // Cross-check the model_has_roles rows land in the tenant team, not team 0.
    $teamZeroAssignments = DB::table('model_has_roles')
        ->whereIn('model_id', [$riya->getKey(), $arjun->getKey()])
        ->where('team_id', 0)
        ->count();
    expect($teamZeroAssignments)->toBe(0);
});

it('can mount the EditTenant page for a wizard-created tenant (no Livewire serialization crash)', function () {
    $tenant = Tenant::factory()->create([
        'settings' => [
            'hours' => ['monday' => ['open' => '09:30', 'close' => '18:30']],
            'sla' => ['first_attempt_window_min' => 60, 'max_attempts' => 5, 'callback_honour_window_hours' => 24],
            'dispositions' => [['code' => 'X', 'label' => 'X', 'is_contact' => true, 'is_sale' => false]],
            'scripts' => ['opening' => 'hi'],
            'portal_access' => false,
            'campaign_template' => CampaignTemplate::OutboundSales->value,
        ],
    ]);

    Livewire::test(EditTenant::class, ['record' => $tenant->getRouteKey()])
        ->assertSuccessful();
});

it('seeds the dispositions list when a campaign template is picked', function () {
    $component = Livewire::test(CreateTenant::class)
        ->fillForm([
            'campaign_template' => CampaignTemplate::EdtechEnrollment->value,
        ]);

    $expected = config('hcims.disposition_templates.'.CampaignTemplate::EdtechEnrollment->value);

    $component->assertSet('data.dispositions', $expected);
});

it('reseeds dispositions when the campaign template changes', function () {
    $component = Livewire::test(CreateTenant::class)
        ->fillForm(['campaign_template' => CampaignTemplate::OutboundSales->value])
        ->fillForm(['campaign_template' => CampaignTemplate::Ndr->value]);

    $expected = config('hcims.disposition_templates.'.CampaignTemplate::Ndr->value);

    $component->assertSet('data.dispositions', $expected);
});

it('allows finishing the wizard with no agents', function () {
    Livewire::test(CreateTenant::class)
        ->fillForm([
            'name' => 'Bare Client',
            'slug' => 'bare-client',
            'campaign_template' => CampaignTemplate::Ndr->value,
            'sla' => [
                'first_attempt_window_min' => 60,
                'max_attempts' => 5,
                'callback_honour_window_hours' => 24,
            ],
            'hours' => [
                'monday' => ['is_open' => true, 'open' => '09:30', 'close' => '18:30'],
                'tuesday' => ['is_open' => false],
                'wednesday' => ['is_open' => false],
                'thursday' => ['is_open' => false],
                'friday' => ['is_open' => false],
                'saturday' => ['is_open' => false],
                'sunday' => ['is_open' => false],
            ],
            'dispositions' => [
                ['code' => 'NO_ANSWER', 'label' => 'No answer', 'is_contact' => false, 'is_sale' => false],
            ],
            'scripts' => [],
            'agents' => [],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $tenant = Tenant::query()->where('slug', 'bare-client')->firstOrFail();

    expect(Role::query()->where('team_id', $tenant->getKey())->count())->toBe(5);
    expect(User::query()->where('email', 'like', '%@bare-client.test')->count())->toBe(0);
});
