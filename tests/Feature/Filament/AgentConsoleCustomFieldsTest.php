<?php

use App\Enums\RoleName;
use App\Filament\Pages\AgentConsole;
use App\Models\CallHandoff;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\PhoneNumber;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::resetWebRequest();
    TenantContext::forget();
});

beforeEach(function () {
    Http::fake(['*' => Http::response(['id' => 'agent-leg'])]);
});

/**
 * CF-2 (campaign-fields-on-console.md) — the client's OWN boxes reach the agent's live
 * call screen, on `callHistory()`'s return value.
 *
 * 🔴 On the return value and not a render, deliberately. One #[Renderless] method
 * anywhere in a Livewire batch drops the HTML for the whole request and the console
 * ships setPresence(), so a server-rendered panel is built and thrown away before it
 * reaches the DOM. Every test passes; nothing shows on screen. That has happened here
 * twice. `callHistory()` is not renderless and is verified live.
 *
 * Which campaign's boxes is CF-1's chain: the matched customer's, else the campaign that
 * owns the number they rang, else the client's generic Inbound bucket.
 */
function insuranceBoxes(): array
{
    return [
        ['key' => 'policy_number', 'label' => 'Policy Number', 'type' => 'text', 'required' => true],
        ['key' => 'plan', 'label' => 'Plan', 'type' => 'select', 'required' => false, 'options' => ['Gold', 'Silver']],
    ];
}

function consoleHoldingCall(Tenant $tenant, User $agent, ?string $dialled): AgentConsole
{
    $ticket = (string) Str::uuid();

    TenantContext::run($tenant->id, fn () => CallHandoff::factory()
        ->forAgent($agent)
        ->ticket($ticket)
        ->create(['arrived_at' => now()->subMinute(), 'dialled_number' => $dialled]));

    $page = new AgentConsole;
    $page->callCorrelationId = $ticket;
    $page->callPartyNumber = '9991234567';

    return $page;
}

it('answers with the matched customer campaign boxes', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $this->actingAs($agent);

    TenantContext::run($tenant->id, function (): void {
        $insurance = Campaign::factory()->withCustomFields(insuranceBoxes())->create();
        $courses = Campaign::factory()->withCustomFields([
            ['key' => 'course', 'label' => 'Course', 'type' => 'text', 'required' => false],
        ])->create();

        PhoneNumber::factory()->create(['number' => '+911772345678', 'campaign_id' => $courses->id]);
        Lead::factory()->create(['phone' => '9991234567', 'campaign_id' => $insurance->id]);
    });

    $page = consoleHoldingCall($tenant, $agent, '+911772345678');

    $fields = TenantContext::run($tenant->id, function () use ($page): array {
        $page->lookupLead('9991234567');

        return $page->callHistory()['fields'];
    });

    // The person wins over the number they rang — CF-1 step 1, and the same order
    // recordCall() already files the call under, so the boxes and the export agree.
    expect(array_column($fields, 'key'))->toBe(['policy_number', 'plan'])
        ->and($fields[0]['required'])->toBeTrue()
        ->and($fields[1]['type'])->toBe('select')
        ->and($fields[1]['options'])->toBe(['Gold', 'Silver'])
        // A non-select box still carries options, as an empty list, so the browser can
        // loop it without asking what type it is first.
        ->and($fields[0]['options'])->toBe([]);
});

it('answers with the dialled number campaign boxes when nobody matched', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $this->actingAs($agent);

    TenantContext::run($tenant->id, function (): void {
        $insurance = Campaign::factory()->withCustomFields(insuranceBoxes())->create();
        PhoneNumber::factory()->create(['number' => '+911772345678', 'campaign_id' => $insurance->id]);
    });

    $page = consoleHoldingCall($tenant, $agent, '+911772345678');

    $fields = TenantContext::run($tenant->id, fn (): array => $page->callHistory()['fields']);

    // Ravi the stranger is the caller this feature exists for: no lead, so no boxes at
    // all under any rule that only reads the match.
    expect(array_column($fields, 'key'))->toBe(['policy_number', 'plan']);
});

it('answers with the inbound bucket boxes when the number belongs to no campaign', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $this->actingAs($agent);

    TenantContext::run($tenant->id, function (): void {
        Campaign::inboundFallback()->update(['custom_fields' => [
            ['key' => 'enquiry', 'label' => 'What they asked about', 'type' => 'text', 'required' => false],
        ]]);
        PhoneNumber::factory()->create(['number' => '+911772345678', 'campaign_id' => null]);
    });

    $page = consoleHoldingCall($tenant, $agent, '+911772345678');

    $fields = TenantContext::run($tenant->id, fn (): array => $page->callHistory()['fields']);

    expect(array_column($fields, 'key'))->toBe(['enquiry']);
});

// A screen pop must not write. Creating a campaign and seeding it a disposition set is
// not something an incoming call should do, and callHistory() runs on every ring.
it('does not create the inbound bucket just to answer', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $this->actingAs($agent);

    $page = consoleHoldingCall($tenant, $agent, null);

    $fields = TenantContext::run($tenant->id, fn (): array => $page->callHistory()['fields']);

    $campaigns = TenantContext::run($tenant->id, fn (): array => Campaign::pluck('name')->all());

    expect($fields)->toBe([])
        ->and($campaigns)->not->toContain(Campaign::INBOUND_NAME);
});

// A definition written by an older or newer shape still draws a usable box.
it('falls back to a text box for a type it does not know', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $this->actingAs($agent);

    TenantContext::run($tenant->id, function (): void {
        $campaign = Campaign::factory()->withCustomFields([
            ['key' => 'signature', 'label' => 'Signature', 'type' => 'rich_text', 'required' => false],
            ['key' => '', 'label' => 'Nameless', 'type' => 'text', 'required' => false],
        ])->create();
        PhoneNumber::factory()->create(['number' => '+911772345678', 'campaign_id' => $campaign->id]);
    });

    $page = consoleHoldingCall($tenant, $agent, '+911772345678');

    $fields = TenantContext::run($tenant->id, fn (): array => $page->callHistory()['fields']);

    // The keyless one is dropped, the unknown type is drawn as text.
    expect($fields)->toHaveCount(1)
        ->and($fields[0]['type'])->toBe('text');
});

// T12, the wall. Assert the identity of what came back, not that a count is zero.
it('never answers with another client campaign boxes', function () {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $this->actingAs($agent);

    TenantContext::run($other->id, function (): void {
        $theirs = Campaign::factory()->withCustomFields(insuranceBoxes())->create();
        PhoneNumber::factory()->create(['number' => '+911772345678', 'campaign_id' => $theirs->id]);
    });

    $page = consoleHoldingCall($tenant, $agent, '+911772345678');

    $fields = TenantContext::run($tenant->id, fn (): array => $page->callHistory()['fields']);

    expect(array_column($fields, 'key'))->not->toContain('policy_number')
        ->and($fields)->toBe([]);
});

// CF-8: the DEFINITIONS come from the campaign, the VALUES come from the person.
it('prefills a known caller stored values on the lead shape', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $this->actingAs($agent);

    TenantContext::run($tenant->id, function (): void {
        $insurance = Campaign::factory()->withCustomFields(insuranceBoxes())->create();
        Lead::factory()->create([
            'phone' => '9991234567',
            'campaign_id' => $insurance->id,
            'custom_fields' => ['policy_number' => 'POL-4471', 'plan' => 'Gold'],
        ]);
    });

    $page = consoleHoldingCall($tenant, $agent, null);

    $lead = TenantContext::run($tenant->id, fn (): ?array => $page->lookupLead('9991234567'));

    // Canonicalizing, not identical: `leads.custom_fields` is jsonb and Postgres stores
    // an object's keys in its own order, not the one they were written in.
    expect($lead['customFields'])->toEqualCanonicalizing(['policy_number' => 'POL-4471', 'plan' => 'Gold']);
});

// T11, the markup half. `callHistory()` answering with the right boxes is worth nothing
// if the block that draws them has been deleted or its state renamed — the exact failure
// no data test can see, and the one this console has shipped twice.
it('carries the custom-box markup bound to the definitions and the values', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $this->actingAs($agent);
    TenantContext::applyWebRequest($tenant->id, crossTenant: false);

    $html = Livewire::test(AgentConsole::class)->html();

    expect($html)->toContain('field in history.fields')
        // The values map, nested so a box keyed `name` cannot overwrite the standard one.
        ->and($html)->toContain('customer.fields[field.key]')
        // CF-5's whole reason for existing: the boxes scroll inside their own area so the
        // call note below them keeps the position CP-5 gave it.
        ->and($html)->toContain('max-h-64')
        ->and($html)->toContain('overflow-y-auto')
        // CF-8: must-fill boxes are marked during the call, not discovered at wrap-up.
        ->and($html)->toContain('x-show="field.required"');
});
