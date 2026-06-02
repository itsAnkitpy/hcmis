<?php

use App\Enums\LeadStatus;
use App\Enums\RoleName;
use App\Filament\Resources\Leads\Pages\CreateLead;
use App\Filament\Resources\Leads\Schemas\LeadForm;
use App\Filament\Support\CampaignCustomFields;
use App\Models\Campaign;
use App\Models\Disposition;
use App\Models\Lead;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::resetWebRequest();
    TenantContext::forget();
});

// --- field-definition -> form-component mapping (the reusable core, FR-LC02) ---

it('maps a campaign custom-field definitions to the matching lead form components', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function () {
        $campaign = Campaign::factory()->withCustomFields([
            ['key' => 'policy_number', 'label' => 'Policy Number', 'type' => 'text', 'required' => true],
            ['key' => 'premium', 'label' => 'Premium', 'type' => 'number', 'required' => false],
            ['key' => 'renews_on', 'label' => 'Renewal Date', 'type' => 'date', 'required' => false],
            ['key' => 'plan', 'label' => 'Plan', 'type' => 'select', 'required' => true, 'options' => ['Gold', 'Silver']],
        ])->create();

        $fields = CampaignCustomFields::valueFields($campaign->id);

        expect($fields)->toHaveCount(4)
            ->and(collect($fields)->map->getName()->all())->toBe([
                'custom_fields.policy_number',
                'custom_fields.premium',
                'custom_fields.renews_on',
                'custom_fields.plan',
            ])
            ->and($fields[0])->toBeInstanceOf(TextInput::class)
            ->and($fields[2])->toBeInstanceOf(DatePicker::class)
            ->and($fields[3])->toBeInstanceOf(Select::class)
            ->and($fields[3]->getOptions())->toBe(['Gold' => 'Gold', 'Silver' => 'Silver']);
    });
});

it('renders no custom fields for an empty campaign, a null id, or a cross-tenant id', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    $foreign = TenantContext::run($b->id, fn () => Campaign::factory()
        ->withCustomFields([['key' => 'secret', 'label' => 'Secret', 'type' => 'text']])
        ->create());

    TenantContext::run($a->id, function () use ($foreign) {
        $plain = Campaign::factory()->create(); // no defined fields

        expect(CampaignCustomFields::valueFields($plain->id))->toBe([])
            ->and(CampaignCustomFields::valueFields(null))->toBe([])
            // the wall hides B's campaign from A — no field leak across tenants.
            ->and(CampaignCustomFields::valueFields($foreign->id))->toBe([]);
    });
});

// --- C1: disposition options scoped to the campaign + tenant-wide ---

it('scopes the lead disposition options to the campaign plus tenant-wide ones (C1)', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function () {
        $campaignA = Campaign::factory()->create();
        $campaignB = Campaign::factory()->create();

        $dispA = Disposition::factory()->forCampaign($campaignA)->create(['label' => 'A only']);
        $dispB = Disposition::factory()->forCampaign($campaignB)->create(['label' => 'B only']);
        $dispWide = Disposition::factory()->create(['label' => 'Tenant wide']); // campaign_id null

        $options = LeadForm::dispositionOptions($campaignA->id);

        expect($options)->toHaveKey($dispA->id)
            ->and($options)->toHaveKey($dispWide->id)
            ->and($options)->not->toHaveKey($dispB->id);
    });
});

// --- end-to-end: the lead form captures + validates custom values ---
// A team_leader pinned to the client mirrors the real SetCurrentTenant flow
// (per-client role resolves at the tenant team; create-gate has a current
// client). Livewire::test must NOT be wrapped in TenantContext::run().

it('persists per-campaign custom-field values entered on the lead form', function () {
    $tenant = Tenant::factory()->create();
    $teamLeader = clientUserWithRole($tenant, RoleName::TeamLeader->value);

    $this->actingAs($teamLeader->fresh());
    TenantContext::applyWebRequest($tenant->id, false);

    $campaign = Campaign::factory()->withCustomFields([
        ['key' => 'policy_number', 'label' => 'Policy Number', 'type' => 'text', 'required' => false],
        ['key' => 'plan', 'label' => 'Plan', 'type' => 'select', 'required' => false, 'options' => ['Gold', 'Silver']],
    ])->create();

    Livewire::test(CreateLead::class)
        ->fillForm([
            'campaign_id' => $campaign->id,
            'name' => 'Asha',
            'phone' => '9990001111',
            'status' => LeadStatus::New->value,
            'attempts' => 0,
            'custom_fields' => [
                'policy_number' => 'PN-42',
                'plan' => 'Gold',
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $lead = Lead::query()->where('phone', '9990001111')->first();

    expect($lead)->not->toBeNull()
        ->and($lead->custom_fields)->toEqual(['policy_number' => 'PN-42', 'plan' => 'Gold'])
        ->and($lead->campaign_id)->toBe($campaign->id)
        ->and($lead->tenant_id)->toBe($tenant->id);
});

it('rejects a lead when a required custom field is left blank', function () {
    $tenant = Tenant::factory()->create();
    $teamLeader = clientUserWithRole($tenant, RoleName::TeamLeader->value);

    $this->actingAs($teamLeader->fresh());
    TenantContext::applyWebRequest($tenant->id, false);

    $campaign = Campaign::factory()->withCustomFields([
        ['key' => 'policy_number', 'label' => 'Policy Number', 'type' => 'text', 'required' => true],
    ])->create();

    Livewire::test(CreateLead::class)
        ->fillForm([
            'campaign_id' => $campaign->id,
            'phone' => '9990002222',
            'status' => LeadStatus::New->value,
            'attempts' => 0,
            // policy_number intentionally omitted
        ])
        ->call('create')
        ->assertHasFormErrors(['custom_fields.policy_number']);
});
