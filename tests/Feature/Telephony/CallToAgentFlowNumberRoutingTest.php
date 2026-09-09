<?php

use App\Models\PhoneNumber;
use App\Models\Tenant;
use App\Telephony\Flows\CallToAgentFlow;
use App\Telephony\Flows\Switchboard;
use App\Telephony\TelephonyProvider;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(fn () => fakeAgentDirectory());   // SEC-1 slice 4: the reserved agent has a phone

/**
 * B2.3a — which client is this call for?
 *
 * The company label's SOURCE changed (the dialled number is now looked up in the
 * phone_numbers list) and nothing downstream did. CallToAgentFlowRoutingTest proves
 * the B2.2b routing mechanics against a stub lookup; this file deliberately uses the
 * REAL lookup against the REAL table, because the whole point of the module is that
 * a second client can now own a number — and a stub cannot prove that.
 *
 * It also pins the ND-4 log split. The two clean-end causes shared one "all busy"
 * message until S85 and it cost an hour of S84 chasing a staffing problem that was
 * really a missing company label.
 */
beforeEach(function () {
    TenantContext::forget();   // the listener has no client in scope — the lookup finds it
    Queue::fake();
});

afterEach(function () {
    TenantContext::forget();
});

it('routes an inbound call to the client that owns the dialled number (ND-1)', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, 'agent');   // a real agent: the flow drops them a handoff note
    TenantContext::run($tenant->id, fn () => PhoneNumber::factory()->create(['number' => '+919876543210']));
    TenantContext::forget();

    $router = fakeAgentRouter($agent->id);

    $telephony = fakeTelephony();
    $telephony->shouldReceive('answer')->once()->with('caller-leg');
    $telephony->shouldReceive('placeCall')->once()->andReturn('agent-leg');

    $switchboard = new Switchboard($telephony);
    (new CallToAgentFlow($telephony, $switchboard))->handle(inboundOn('caller-leg', '+919876543210'));

    // The board read was for THIS client — no hardcoded company anywhere in the path.
    expect($router->reserved)->toBe([$tenant->id]);
});

it('sends two clients their own calls — the first time two numbers have existed (CP case 3)', function () {
    $uber = Tenant::factory()->create();
    $examPur = Tenant::factory()->create();
    $uberAgent = clientUserWithRole($uber, 'agent');
    $examPurAgent = clientUserWithRole($examPur, 'agent');

    TenantContext::run($uber->id, fn () => PhoneNumber::factory()->create(['number' => '+911111111111']));
    TenantContext::run($examPur->id, fn () => PhoneNumber::factory()->create(['number' => '+912222222222']));
    TenantContext::forget();

    $telephony = fakeTelephony();
    $telephony->shouldReceive('answer')->twice();
    $telephony->shouldReceive('placeCall')->twice()->andReturn('agent-leg-1', 'agent-leg-2');
    $switchboard = new Switchboard($telephony);

    $uberRouter = fakeAgentRouter($uberAgent->id);
    (new CallToAgentFlow($telephony, $switchboard))->handle(inboundOn('leg-1', '+911111111111'));

    $examPurRouter = fakeAgentRouter($examPurAgent->id);
    (new CallToAgentFlow($telephony, $switchboard))->handle(inboundOn('leg-2', '+912222222222'));

    // Each call read its OWN client's board. Impossible before B2.3a: every inbound
    // call was stamped client 1 by the dialplan, whichever number it arrived on.
    expect($uberRouter->reserved)->toBe([$uber->id])
        ->and($examPurRouter->reserved)->toBe([$examPur->id]);
});

it('ends a call on a switched-off number cleanly and never reads any board (ND-4)', function () {
    $tenant = Tenant::factory()->create();
    TenantContext::run($tenant->id, fn () => PhoneNumber::factory()->inactive()->create(['number' => '+919876543210']));
    TenantContext::forget();

    $router = fakeAgentRouter(6);

    $telephony = fakeTelephony();
    $telephony->shouldReceive('hangup')->once()->with('caller-leg');
    $telephony->shouldNotReceive('answer');
    $telephony->shouldNotReceive('placeCall');

    $switchboard = new Switchboard($telephony);
    (new CallToAgentFlow($telephony, $switchboard))->handle(inboundOn('caller-leg', '+919876543210'));

    expect($router->reserved)->toBe([]);
});

it('ends a call on a number nobody owns cleanly (ND-4)', function () {
    $router = fakeAgentRouter(6);

    $telephony = fakeTelephony();
    $telephony->shouldReceive('hangup')->once()->with('caller-leg');
    $telephony->shouldNotReceive('answer');

    $switchboard = new Switchboard($telephony);
    (new CallToAgentFlow($telephony, $switchboard))->handle(inboundOn('caller-leg', '+919999999999'));

    expect($router->reserved)->toBe([]);
});

// --- ND-4: the two clean-end causes stopped sharing one misleading message ---

it('says "unknown number", not "all busy", when nobody owns the dialled number', function () {
    Log::spy();

    fakeAgentRouter(6);   // an agent IS free, so "all busy" would be a lie

    $telephony = fakeTelephony();
    $telephony->shouldReceive('hangup')->once()->with('caller-leg');

    $switchboard = new Switchboard($telephony);
    (new CallToAgentFlow($telephony, $switchboard))->handle(inboundOn('caller-leg', '+919999999999'));

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message): bool => str_contains($message, 'unknown number'))
        ->once();

    Log::shouldNotHaveReceived('info', [Mockery::pattern('/all busy/'), Mockery::any()]);
});

// ND-4's point survives B2.3b-i, with the second half rewritten: a KNOWN client with
// nobody free no longer ends the call at all — the caller waits (QD-4). The two causes
// still have to read differently in the log, which is the whole reason ND-4 split them.
it('holds the caller with music when the client is known but nobody is free (RD-5, now the waiting room)', function () {
    Log::spy();

    $tenant = Tenant::factory()->create();
    TenantContext::run($tenant->id, fn () => PhoneNumber::factory()->create(['number' => '+919876543210']));
    TenantContext::forget();

    fakeAgentRouter(null);   // the number resolves; nobody is free

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-leg');
    $telephony->shouldReceive('startHoldMusic')->once()->with('caller-leg');
    $telephony->shouldNotReceive('hangup');

    $switchboard = new Switchboard($telephony);
    (new CallToAgentFlow($telephony, $switchboard))->handle(inboundOn('caller-leg', '+919876543210'));

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message): bool => str_contains($message, 'holding with music'))
        ->once();
});
