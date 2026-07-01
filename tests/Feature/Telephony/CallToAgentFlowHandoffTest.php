<?php

use App\Models\CallHandoff;
use App\Models\Tenant;
use App\Telephony\Flows\CallToAgentFlow;
use App\Telephony\Flows\Switchboard;
use App\Telephony\TelephonyProvider;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

/**
 * B2.4b TH-5/TH-6 — the watcher-side ticket handoff. beginCall drops the call's ticket
 * on the call_handoffs drawer for the reserved agent, scoped to the call's own company
 * (the listener has no logged-in user), BEFORE the phone rings — so the agent's screen
 * reads it at ring-time. Prune-then-insert caps the drawer at one note per agent.
 *
 * DB-backed (unlike the pure-mock flow tests) because the write is the thing under test:
 * a real tenant + a real reserved agent so the FK + RLS write actually lands.
 */
uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::forget();
});

beforeEach(function () {
    config()->set('telephony.agent.endpoint', 'PJSIP/1003');
    config()->set('telephony.agent.directory', []);
    Queue::fake();
});

it('drops a handoff note for the reserved agent in the call\'s own company, carrying the ticket (TH-5)', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, 'agent');
    fakeAgentRouter($agent->id);   // the board hands back this agent

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-leg');
    $telephony->shouldReceive('placeCall')->once()->andReturn('agent-leg');

    $switchboard = new Switchboard($telephony);
    $flow = new CallToAgentFlow($telephony, $switchboard);
    $flow->handle(stasisStart('caller-leg', [], null, (string) $tenant->id));

    $note = TenantContext::run(
        $tenant->id,
        fn () => CallHandoff::query()->where('agent_user_id', $agent->id)->first(),
    );

    expect($note)->not->toBeNull()
        ->and($note->tenant_id)->toBe($tenant->id)            // scoped to the call's company (TH-5)
        ->and($note->ticket)->toBe($flow->ticketNumber());    // the SAME ticket the recording carries (TH-4)
});

it('prunes the agent\'s prior note on the next ring — at most one note per agent (TH-6)', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, 'agent');
    fakeAgentRouter($agent->id);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer');
    $telephony->shouldReceive('placeCall')->andReturn('agent-leg');

    $switchboard = new Switchboard($telephony);

    // First inbound call to this agent -> note #1.
    (new CallToAgentFlow($telephony, $switchboard))->handle(stasisStart('caller-1', [], null, (string) $tenant->id));
    // A second inbound call reserving the SAME agent -> prune #1, write #2.
    $second = new CallToAgentFlow($telephony, $switchboard);
    $second->handle(stasisStart('caller-2', [], null, (string) $tenant->id));

    $notes = TenantContext::run(
        $tenant->id,
        fn () => CallHandoff::query()->where('agent_user_id', $agent->id)->get(),
    );

    expect($notes)->toHaveCount(1)                                  // prune-on-write capped it at one
        ->and($notes->first()->ticket)->toBe($second->ticketNumber()); // the latest ring's ticket wins
});

it('writes no handoff note when the call carries no company label (TH-5 — no orphan write)', function () {
    fakeAgentRouter(6);   // would hand back an agent — but it is never asked without a label

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('hangup')->once()->with('caller-leg');   // RD-5 clean end
    $telephony->shouldNotReceive('answer');
    $telephony->shouldNotReceive('placeCall');

    $switchboard = new Switchboard($telephony);
    // tenantId: null -> an unlabelled inbound call (no front-door company sticker).
    (new CallToAgentFlow($telephony, $switchboard))->handle(stasisStart('caller-leg', [], null, null));

    // No agent reserved -> no note, anywhere (checked across the wall).
    $count = TenantContext::cross(fn () => CallHandoff::query()->count());
    expect($count)->toBe(0);
});
