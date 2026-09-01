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

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::resetWebRequest();
    TenantContext::forget();
});

beforeEach(function () {
    Http::fake(['*' => Http::response(['id' => 'agent-leg'])]);
});

/**
 * CF-1 + F6 (campaign-fields-on-console.md) — a customer saved mid-call is filed under
 * the same campaign the call itself is filed under.
 *
 * 🔴 F6, live before this: `recordCall()` filed the CALL under the dialled number's
 * campaign (:1323) while `saveCustomer()` filed the PERSON under the generic Inbound
 * bucket, ignoring the dialled number entirely. Ravi rings Acme's insurance line and the
 * two rows disagreed about which campaign he belonged to.
 *
 * It matters beyond tidiness, which is why CF-1 could not leave it: the export prints the
 * columns a CAMPAIGN defines and reads the values off the LEAD, so a detail typed against
 * one campaign and stored on a person filed under another never reaches the client.
 *
 * Built through a real inbound ticket, because the dialled number only exists on the
 * handoff note — which is the whole input CF-1 turns on.
 */
function consoleOnInboundCall(Tenant $tenant, User $agent, ?string $dialled): AgentConsole
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

it('files a new customer under the campaign that owns the number they rang', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $this->actingAs($agent);

    $insurance = TenantContext::run($tenant->id, function (): Campaign {
        $insurance = Campaign::factory()->create(['name' => 'Insurance Renewals']);
        PhoneNumber::factory()->create(['number' => '+911772345678', 'campaign_id' => $insurance->id]);

        return $insurance;
    });

    $page = consoleOnInboundCall($tenant, $agent, '+911772345678');

    TenantContext::run($tenant->id, fn () => $page->saveCustomer('Ravi Menon'));

    $saved = TenantContext::run($tenant->id, fn (): Lead => Lead::with('campaign')->where('phone', '9991234567')->sole());

    // Identity, not a count: the generic bucket would also be "a campaign".
    expect($saved->campaign_id)->toBe($insurance->id)
        ->and($saved->campaign->name)->toBe('Insurance Renewals');
});

it('falls back to the client own inbound bucket when the number has no campaign', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $this->actingAs($agent);

    TenantContext::run($tenant->id, fn () => PhoneNumber::factory()
        ->create(['number' => '+911772345678', 'campaign_id' => null]));

    $page = consoleOnInboundCall($tenant, $agent, '+911772345678');

    TenantContext::run($tenant->id, fn () => $page->saveCustomer('Ravi Menon'));

    $saved = TenantContext::run($tenant->id, fn (): Lead => Lead::with('campaign')->where('phone', '9991234567')->sole());

    expect($saved->campaign->name)->toBe(Campaign::INBOUND_NAME);
});

// F6 stated as the thing a reader actually cares about: the two rows agree.
it('files the customer and the call under the same campaign', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $this->actingAs($agent);

    [$insurance, $disposition] = TenantContext::run($tenant->id, function (): array {
        $insurance = Campaign::factory()->create(['name' => 'Insurance Renewals']);
        PhoneNumber::factory()->create(['number' => '+911772345678', 'campaign_id' => $insurance->id]);

        return [$insurance, Disposition::factory()->create(['campaign_id' => $insurance->id])];
    });

    $page = consoleOnInboundCall($tenant, $agent, '+911772345678');

    TenantContext::run($tenant->id, function () use ($page, $disposition): void {
        $page->saveCustomer('Ravi Menon');
        $page->saveWrapUp($disposition->id);
    });

    [$lead, $call] = TenantContext::run($tenant->id, fn (): array => [
        Lead::with('campaign')->where('phone', '9991234567')->sole(),
        Call::sole(),
    ]);

    expect($call->campaign_id)->toBe($insurance->id)
        ->and($lead->campaign_id)->toBe($insurance->id);
});

// A matched customer keeps the campaign they are already filed under — the save updates
// the typed details and never re-files the person. This is CF-1 step 1, and it is also
// why the cross-product-line caller is a modelling limit rather than an ordering bug.
it('never re-files a customer who is already matched', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $this->actingAs($agent);

    $courses = TenantContext::run($tenant->id, function (): Campaign {
        $insurance = Campaign::factory()->create(['name' => 'Insurance Renewals']);
        PhoneNumber::factory()->create(['number' => '+911772345678', 'campaign_id' => $insurance->id]);
        $courses = Campaign::factory()->create(['name' => 'Course Enrolments']);
        Lead::factory()->create(['phone' => '9991234567', 'campaign_id' => $courses->id]);

        return $courses;
    });

    $page = consoleOnInboundCall($tenant, $agent, '+911772345678');

    TenantContext::run($tenant->id, fn () => $page->saveCustomer('Meera Nair'));

    $saved = TenantContext::run($tenant->id, fn (): Lead => Lead::with('campaign')->where('phone', '9991234567')->sole());

    expect($saved->campaign_id)->toBe($courses->id)
        ->and($saved->name)->toBe('Meera Nair');
});

// The wall. A number is another client's fact; reading it across would file this client's
// customer under a campaign they cannot see.
it('ignores a number that belongs to another client', function () {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $this->actingAs($agent);

    $theirs = TenantContext::run($other->id, function (): Campaign {
        $theirs = Campaign::factory()->create(['name' => 'Their campaign']);
        PhoneNumber::factory()->create(['number' => '+911772345678', 'campaign_id' => $theirs->id]);

        return $theirs;
    });

    $page = consoleOnInboundCall($tenant, $agent, '+911772345678');

    TenantContext::run($tenant->id, fn () => $page->saveCustomer('Ravi Menon'));

    $saved = TenantContext::run($tenant->id, fn (): Lead => Lead::with('campaign')->where('phone', '9991234567')->sole());

    expect($saved->campaign_id)->not->toBe($theirs->id)
        ->and($saved->campaign->name)->toBe(Campaign::INBOUND_NAME);
});
