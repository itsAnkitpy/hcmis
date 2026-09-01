<?php

use App\Enums\RoleName;
use App\Filament\Pages\AgentConsole;
use App\Models\Call;
use App\Models\CallHandoff;
use App\Models\Campaign;
use App\Models\Disposition;
use App\Models\Lead;
use App\Models\PhoneNumber;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
        ->and($html)->toContain('x-show="field.required"')
        // A dropdown's stored value has to be re-applied after its options exist. Without
        // this the box reads "Not captured yet" over a value that is stored and correct —
        // it showed on the Leads screen and not on the console.
        ->and($html)->toContain('$nextTick(() => { $el.value = customer.fields[field.key]');
});

/**
 * CF-3 — the mid-call save MERGES onto what is stored, and never replaces it.
 *
 * The failure this exists to stop is silent: Meera has POL-4471 filed against her, the
 * agent changes only her Plan, and a wholesale replace drops the policy number with
 * nothing on screen to say so. One rendering failure, one box added to the campaign an
 * hour ago, one type we draw badly, and a stored value is simply gone.
 */
function consoleOnCallWithBoxes(Tenant $tenant, User $agent): AgentConsole
{
    TenantContext::run($tenant->id, function (): void {
        $insurance = Campaign::factory()->withCustomFields(insuranceBoxes())->create();
        PhoneNumber::factory()->create(['number' => '+911772345678', 'campaign_id' => $insurance->id]);
    });

    return consoleHoldingCall($tenant, $agent, '+911772345678');
}

it('keeps a stored box the save never sent', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $this->actingAs($agent);

    $page = consoleOnCallWithBoxes($tenant, $agent);

    TenantContext::run($tenant->id, function () use ($page): void {
        $page->saveCustomer('Meera Nair', null, null, ['policy_number' => 'POL-4471', 'plan' => 'Gold']);
        // The second save carries only Plan — exactly what a browser sends when a box was
        // never drawn, or was drawn and left alone.
        $page->saveCustomer(null, null, null, ['plan' => 'Silver']);
    });

    $saved = TenantContext::run($tenant->id, fn (): Lead => Lead::where('phone', '9991234567')->sole());

    expect($saved->custom_fields)->toEqualCanonicalizing([
        'policy_number' => 'POL-4471',
        'plan' => 'Silver',
    ]);
});

it('drops a box name the campaign does not define', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $this->actingAs($agent);

    $page = consoleOnCallWithBoxes($tenant, $agent);

    TenantContext::run($tenant->id, fn () => $page->saveCustomer(
        'Meera Nair', null, null,
        ['policy_number' => 'POL-4471', 'is_admin' => true, 'internal_score' => 99],
    ));

    $saved = TenantContext::run($tenant->id, fn (): Lead => Lead::where('phone', '9991234567')->sole());

    // Silently, the same posture LeadsImport takes with a column nobody defined.
    expect($saved->custom_fields)->toBe(['policy_number' => 'POL-4471']);
});

it('saves when only a client box is filled and all three standard boxes are empty', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $this->actingAs($agent);

    $page = consoleOnCallWithBoxes($tenant, $agent);

    TenantContext::run($tenant->id, fn () => $page->saveCustomer(null, null, null, ['policy_number' => 'POL-4471']));

    $saved = TenantContext::run($tenant->id, fn (): Lead => Lead::where('phone', '9991234567')->sole());

    // Before CF-3 this was refused 422: three empty standard boxes read as "nothing
    // typed" when the only box that mattered was full.
    expect($saved->custom_fields)->toBe(['policy_number' => 'POL-4471'])
        ->and($saved->name)->toBeNull();
});

it('still refuses a save with nothing in it at all', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $this->actingAs($agent);

    $page = consoleOnCallWithBoxes($tenant, $agent);

    expect(fn () => TenantContext::run($tenant->id, fn () => $page->saveCustomer(null, null, null, ['policy_number' => ''])))
        ->toThrow(HttpException::class);

    expect(TenantContext::run($tenant->id, fn (): int => Lead::count()))->toBe(0);
});

it('refuses a dropdown value the client never defined', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $this->actingAs($agent);

    $page = consoleOnCallWithBoxes($tenant, $agent);

    // Otherwise the client's option list is decoration and the export prints anything.
    expect(fn () => TenantContext::run($tenant->id, fn () => $page->saveCustomer('Meera', null, null, ['plan' => 'Platinum'])))
        ->toThrow(ValidationException::class);
});

it('stores the client boxes for a brand new customer', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $this->actingAs($agent);

    $page = consoleOnCallWithBoxes($tenant, $agent);

    TenantContext::run($tenant->id, fn () => $page->saveCustomer(
        'Ravi Menon', null, null,
        ['policy_number' => 'POL-9001', 'plan' => 'Gold'],
    ));

    $saved = TenantContext::run($tenant->id, fn (): Lead => Lead::where('phone', '9991234567')->sole());

    expect($saved->custom_fields)->toEqualCanonicalizing(['policy_number' => 'POL-9001', 'plan' => 'Gold']);
});

// The reported scenario, end to end: a customer who ALREADY EXISTS (a seeded lead, an
// imported one, anybody the agent did not create) rings in, matches, and the agent types
// into the client's boxes for the first time. Their stored map is empty, not absent.
it('stores the client boxes on a customer who already existed', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $this->actingAs($agent);

    TenantContext::run($tenant->id, function (): void {
        $insurance = Campaign::factory()->withCustomFields(insuranceBoxes())->create();
        Lead::factory()->create([
            'phone' => '9991234567',
            'campaign_id' => $insurance->id,
            'name' => 'Prof. Blanca Wuckert',
        ]);
    });

    $page = consoleHoldingCall($tenant, $agent, null);

    $shape = TenantContext::run($tenant->id, function () use ($page): array {
        // The ring matches them first — this is what makes the button say "Update".
        $page->lookupLead('9991234567');

        return $page->saveCustomer('Prof. Blanca Wuckert', null, null, [
            'policy_number' => 'POL-4471',
            'plan' => 'Gold',
        ]);
    });

    $saved = TenantContext::run($tenant->id, fn (): Lead => Lead::where('phone', '9991234567')->sole());

    expect($saved->custom_fields)->toEqualCanonicalizing(['policy_number' => 'POL-4471', 'plan' => 'Gold'])
        // And the save's own reply carries them back, so the boxes stay filled on screen
        // rather than blanking the instant the agent presses the button.
        ->and($shape['customFields'])->toEqualCanonicalizing(['policy_number' => 'POL-4471', 'plan' => 'Gold']);
});

/**
 * CF-6 — the wrap-up Save carries the client's boxes too, and the wrap-up screen DRAWS
 * them.
 *
 * The trap it closes: the agent types Ravi's policy number into the box, never presses
 * "Save customer" because they are busy talking, and the call ends. The value only ever
 * existed on screen. CF-4 then refuses the wrap-up for a box the agent has already
 * filled — and by then the live-call card is gone, so there is nowhere to fill it again.
 *
 * So the values ride to saveWrapUp() and are written inside the same transaction as the
 * call row, BEFORE the must-fill check CF-4 adds. Merged, never replaced, for the same
 * reason the mid-call save merges.
 */
function wrapUpConsoleWithBoxes(Tenant $tenant, User $agent, array $stored = []): array
{
    $dispositionId = TenantContext::run($tenant->id, function () use ($stored): array {
        $insurance = Campaign::factory()->withCustomFields(insuranceBoxes())->create();

        Lead::factory()->create([
            'phone' => '9991234567',
            'campaign_id' => $insurance->id,
            'name' => 'Ravi Menon',
            'custom_fields' => $stored,
        ]);

        return [
            Disposition::factory()->forCampaign($insurance)->create(['is_contact' => true, 'label' => 'Interested'])->id,
            Disposition::factory()->forCampaign($insurance)->create(['is_contact' => false, 'label' => 'Voicemail'])->id,
        ];
    });

    return [consoleHoldingCall($tenant, $agent, null), ...$dispositionId];
}

it('stores the boxes sent at wrap-up and keeps the ones it was not sent', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $this->actingAs($agent);

    [$page, $dispositionId] = wrapUpConsoleWithBoxes($tenant, $agent, ['policy_number' => 'POL-4471']);

    TenantContext::run($tenant->id, function () use ($page, $dispositionId): void {
        $page->lookupLead('9991234567');
        // Plan was typed mid-call and "Save customer" was never pressed. Policy Number is
        // already stored and this save does not carry it.
        $page->saveWrapUp($dispositionId, null, null, false, null, ['plan' => 'Gold']);
    });

    $saved = TenantContext::run($tenant->id, fn (): Lead => Lead::where('phone', '9991234567')->sole());

    expect($saved->custom_fields)->toEqualCanonicalizing([
        'policy_number' => 'POL-4471',
        'plan' => 'Gold',
    ])
        // The wrap-up's own writes still happened — the boxes ride along with them, they
        // do not replace them.
        ->and($saved->last_disposition_id)->toBe($dispositionId)
        ->and($saved->attempts)->toBe(1);
});

it('drops a box name the campaign does not define at wrap-up too', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $this->actingAs($agent);

    [$page, $dispositionId] = wrapUpConsoleWithBoxes($tenant, $agent);

    // Same allow-list as the mid-call save, because it is literally the same method:
    // validate() answers with the validated attributes only, so an undefined name has no
    // rule and never reaches the write.
    TenantContext::run($tenant->id, function () use ($page, $dispositionId): void {
        $page->lookupLead('9991234567');
        $page->saveWrapUp($dispositionId, null, null, false, null, [
            'policy_number' => 'POL-9001',
            'is_admin' => true,
        ]);
    });

    $saved = TenantContext::run($tenant->id, fn (): Lead => Lead::where('phone', '9991234567')->sole());

    expect($saved->custom_fields)->toEqualCanonicalizing(['policy_number' => 'POL-9001']);
});

/**
 * The markup half, and it is the half that matters most here. CF-4 refuses the wrap-up
 * when a must-fill box is blank; if the wrap-up screen does not draw the boxes, the agent
 * is blocked with no way to unblock themselves. Delete the include and every data test
 * above still passes.
 */
it('draws the same boxes again on the wrap-up screen, under their own ids', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $this->actingAs($agent);
    TenantContext::applyWebRequest($tenant->id, crossTenant: false);

    $html = Livewire::test(AgentConsole::class)->html();

    // Two copies of the panel: the live-call card and the wrap-up card.
    expect(substr_count($html, 'field in history.fields'))->toBe(2)
        // Distinct id prefixes, so the two copies' labels point at their own inputs
        // rather than both at the first one.
        ->and($html)->toContain("'liveCallField-' + field.key")
        ->and($html)->toContain("'wrapUpField-' + field.key");
});

/**
 * CF-4 — a must-fill box blocks the wrap-up, and ONLY when the agent actually reached a
 * person. This is the only decision in N1c that can stop an agent finishing a call, and
 * it ships last for that reason.
 *
 * Non-contact outcomes are never blocked: nobody needs a policy number off a voicemail,
 * and every mandatory field is a charge on handle time multiplied by call volume. Gating
 * the requirement on state rather than shipping a bypass is the researched shape —
 * Zendesk gates on ticket status, Amazon Connect on a field condition. Scoping it per
 * disposition is CP-10.
 *
 * The refusal comes back as a STRING, not a thrown 422, because a blank box is ordinary
 * and the agent's to fix. F7 keeps them on the wrap-up screen and CF-6 draws the boxes
 * there, so the box the message names is one they can actually go and fill.
 */
it('refuses a contact outcome while a must-fill box is blank, and writes nothing', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $this->actingAs($agent);

    // Plan is filled, Policy Number is not — and only Policy Number is must-fill.
    [$page, $contactId] = wrapUpConsoleWithBoxes($tenant, $agent, ['plan' => 'Gold']);

    $refusal = TenantContext::run($tenant->id, function () use ($page, $contactId): ?string {
        $page->lookupLead('9991234567');

        return $page->saveWrapUp($contactId);
    });

    // Named by its LABEL, which is what the box on the agent's screen says.
    expect($refusal)->toBeString()
        ->and($refusal)->toContain('Policy Number');

    [$saved, $callCount] = TenantContext::run($tenant->id, fn (): array => [
        Lead::where('phone', '9991234567')->sole(),
        Call::count(),
    ]);

    // 🔴 Nothing was written. The refusal happens before the transaction, so there is no
    // half-recorded call to reconcile — the agent fills the box and saves again.
    expect($callCount)->toBe(0)
        ->and($saved->last_disposition_id)->toBeNull()
        ->and($saved->attempts)->toBe(0);
});

it('allows a non-contact outcome with the same box blank', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $this->actingAs($agent);

    [$page, , $nonContactId] = wrapUpConsoleWithBoxes($tenant, $agent);

    $refusal = TenantContext::run($tenant->id, function () use ($page, $nonContactId): ?string {
        $page->lookupLead('9991234567');

        return $page->saveWrapUp($nonContactId);
    });

    [$saved, $callCount] = TenantContext::run($tenant->id, fn (): array => [
        Lead::where('phone', '9991234567')->sole(),
        Call::count(),
    ]);

    expect($refusal)->toBeNull()
        ->and($callCount)->toBe(1)
        ->and($saved->last_disposition_id)->toBe($nonContactId);
});

/**
 * T10 — the CF-6/CF-4 interaction, and the reason CF-6 had to ship first. The agent typed
 * the policy number mid-call and never pressed "Save customer", so the STORED record is
 * still blank. Checking what is stored would refuse them while they stare at the value.
 */
it('accepts a must-fill value the wrap-up is carrying but nobody saved mid-call', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $this->actingAs($agent);

    [$page, $contactId] = wrapUpConsoleWithBoxes($tenant, $agent);

    $refusal = TenantContext::run($tenant->id, function () use ($page, $contactId): ?string {
        $page->lookupLead('9991234567');

        return $page->saveWrapUp($contactId, null, null, false, null, ['policy_number' => 'POL-9001']);
    });

    $saved = TenantContext::run($tenant->id, fn (): Lead => Lead::where('phone', '9991234567')->sole());

    expect($refusal)->toBeNull()
        ->and($saved->custom_fields)->toEqualCanonicalizing(['policy_number' => 'POL-9001'])
        ->and($saved->attempts)->toBe(1);
});

/**
 * T9 — the no-match path is never blocked. A genuine stranger the agent never saved has
 * no customer record, so there is nothing to require anything of. Wrong numbers need no
 * special handling; this test exists to keep it that way.
 */
it('never blocks the no-match wrap-up, whatever the campaign requires', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $this->actingAs($agent);

    // The campaign defines a must-fill Policy Number; nobody has been matched or saved.
    $page = consoleOnCallWithBoxes($tenant, $agent);

    TenantContext::run($tenant->id, fn () => $page->completeUnmatched('Wrong number.'));

    expect(TenantContext::run($tenant->id, fn (): int => Call::count()))->toBe(1);
});
