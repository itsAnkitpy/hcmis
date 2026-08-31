<?php

use App\Enums\RoleName;
use App\Filament\Pages\AgentConsole;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::resetWebRequest();
    TenantContext::forget();
});

/**
 * CP-3 / CP-4 (customer-profiling.md §6) — the live-call customer form, over the wire
 * the browser actually uses.
 *
 * The contract worth pinning: the save writes against the number the SERVER holds (no
 * phone rides in from the browser), a known caller is updated rather than duplicated
 * against `leads_tenant_id_phone_unique`, an unknown caller is created under the
 * client's own "Inbound" campaign because `leads.campaign_id` cannot be null, and none
 * of it leaks across the tenant wall.
 *
 * Set up through a real ad-hoc dial, because that is the path that puts a number in the
 * console's hand without any lead existing — the exact case CP-4 was written for.
 */
function consoleDialing(Tenant $tenant, string $number): Testable
{
    config()->set('telephony.agent.endpoint', 'PJSIP/1003');
    Http::fake(['*' => Http::response(['id' => 'agent-leg'])]);

    return Livewire::test(AgentConsole::class)->call('dialAdhoc', $number);
}

it('creates the customer under the client own inbound campaign when the number is unknown', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $this->actingAs($agent);
    TenantContext::applyWebRequest($tenant->id, crossTenant: false);

    consoleDialing($tenant, '+919805907341')
        ->call('saveCustomer', 'Meera Nair', 'meera@example.test', 'Kochi')
        ->assertReturned(fn (array $lead): bool => $lead['name'] === 'Meera Nair'
            && $lead['email'] === 'meera@example.test'
            && $lead['city'] === 'Kochi'
            && $lead['campaign'] === Campaign::INBOUND_NAME);

    $saved = TenantContext::run($tenant->id, fn (): Lead => Lead::where('phone', '+919805907341')->sole());

    expect($saved->name)->toBe('Meera Nair')
        ->and($saved->email)->toBe('meera@example.test')
        ->and($saved->city)->toBe('Kochi')
        ->and($saved->campaign->name)->toBe(Campaign::INBOUND_NAME)
        ->and($saved->tenant_id)->toBe($tenant->id);
});

it('reuses the one inbound campaign rather than making a second', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $this->actingAs($agent);
    TenantContext::applyWebRequest($tenant->id, crossTenant: false);

    consoleDialing($tenant, '+919805907341')->call('saveCustomer', 'First Caller');
    consoleDialing($tenant, '+919805907342')->call('saveCustomer', 'Second Caller');

    $campaigns = TenantContext::run(
        $tenant->id,
        fn (): array => Campaign::where('name', Campaign::INBOUND_NAME)->pluck('id')->all(),
    );

    expect($campaigns)->toHaveCount(1);
});

// The seeded campaign is only useful if the agent can then record an outcome against it
// — an inbound campaign with an empty disposition list would file the customer and leave
// the wrap-up as blank as it was before.
it('gives the inbound campaign a real disposition set', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $this->actingAs($agent);
    TenantContext::applyWebRequest($tenant->id, crossTenant: false);

    consoleDialing($tenant, '+919805907341')
        ->call('saveCustomer', 'Meera Nair')
        ->call('dispositions')
        ->assertReturned(fn (array $options): bool => $options !== []);
});

it('updates the customer already on that number instead of creating a second', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $existingId = TenantContext::run($tenant->id, function (): int {
        $campaign = Campaign::factory()->create(['name' => 'Summer Push']);

        return Lead::factory()->forCampaign($campaign)->create([
            'phone' => '+919805907341',
            'name' => 'Old Name',
            'email' => 'old@example.test',
        ])->id;
    });

    $this->actingAs($agent);
    TenantContext::applyWebRequest($tenant->id, crossTenant: false);

    consoleDialing($tenant, '+919805907341')
        ->call('saveCustomer', 'New Name', null, 'Kochi')
        ->assertReturned(fn (array $lead): bool => $lead['id'] === $existingId
            && $lead['campaign'] === 'Summer Push');

    $leads = TenantContext::run($tenant->id, fn (): array => Lead::where('phone', '+919805907341')->get()->all());

    expect($leads)->toHaveCount(1);

    $lead = $leads[0];

    expect($lead->id)->toBe($existingId)
        ->and($lead->name)->toBe('New Name')
        ->and($lead->city)->toBe('Kochi')
        // A blank box leaves what is stored alone — this form cannot clear a field.
        ->and($lead->email)->toBe('old@example.test');
});

// The wall, named-not-counted: client B's customer sits on the same number, and client
// A's agent saving it must produce A's OWN new record, never a write into B's row.
it('never writes another client customer on the same number', function () {
    $clientA = Tenant::factory()->create();
    $clientB = Tenant::factory()->create();

    $bLeadId = TenantContext::run($clientB->id, fn (): int => Lead::factory()->create([
        'phone' => '+919805907341',
        'name' => 'Belongs To B',
    ])->id);

    $agentA = clientUserWithRole($clientA, RoleName::Agent->value);

    $this->actingAs($agentA);
    TenantContext::applyWebRequest($clientA->id, crossTenant: false);

    consoleDialing($clientA, '+919805907341')->call('saveCustomer', 'Belongs To A');

    $bLead = TenantContext::run($clientB->id, fn (): Lead => Lead::findOrFail($bLeadId));
    $aLead = TenantContext::run($clientA->id, fn (): Lead => Lead::where('phone', '+919805907341')->sole());

    expect($bLead->name)->toBe('Belongs To B')
        ->and($aLead->id)->not->toBe($bLeadId)
        ->and($aLead->name)->toBe('Belongs To A')
        ->and($aLead->tenant_id)->toBe($clientA->id);
});

// No call in hand means no number in hand, and the number is the key this write turns.
it('refuses to save when no call has set a number', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $this->actingAs($agent);
    TenantContext::applyWebRequest($tenant->id, crossTenant: false);

    Livewire::test(AgentConsole::class)
        ->call('saveCustomer', 'Nobody')
        ->assertStatus(422);

    expect(TenantContext::run($tenant->id, fn (): int => Lead::count()))->toBe(0);
});

it('refuses an empty save rather than filing a customer with nothing but a number', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $this->actingAs($agent);
    TenantContext::applyWebRequest($tenant->id, crossTenant: false);

    consoleDialing($tenant, '+919805907341')
        ->call('saveCustomer', '', '', '')
        ->assertStatus(422);

    expect(TenantContext::run($tenant->id, fn (): int => Lead::count()))->toBe(0);
});

// The markup half — the same lesson CH-2 earned. A save method that works is worth
// nothing if the form bound to it has been deleted or its state renamed.
it('carries the form markup bound to the customer state', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $this->actingAs($agent);
    TenantContext::applyWebRequest($tenant->id, crossTenant: false);

    $html = Livewire::test(AgentConsole::class)->html();

    expect($html)->toContain('customer.name')
        ->and($html)->toContain('customer.email')
        ->and($html)->toContain('customer.city')
        ->and($html)->toContain('saveCustomer()');
});
