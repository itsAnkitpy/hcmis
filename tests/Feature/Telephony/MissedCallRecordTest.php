<?php

use App\Enums\CallDirection;
use App\Enums\CallOutcome;
use App\Models\Call;
use App\Models\Tenant;
use App\Telephony\Flows\CallToAgentFlow;
use App\Telephony\Flows\Switchboard;
use App\Telephony\RecordingSession;
use App\Telephony\TelephonyProvider;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;

/**
 * B2.3b-i QD-5/QD-6 — the missed-call record: the row written for a caller no agent
 * ever reached. It is the one row the listener CREATES rather than enriches, and it is
 * D2's own named seam arriving, not a break of it: nobody wraps up a call they never
 * took, so no screen exists to write it.
 *
 * Without this the callers the waiting room exists to save would be exactly the ones
 * invisible to every call report — which is the long-standing Asterisk default the
 * industry check turned up, and the bug this slice was about to build.
 *
 * DB-backed (unlike the mock-only flow tests) because the write is the thing under
 * test: a real client, so the row's ownership and the tenant wall are real.
 */
uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::forget();
});

beforeEach(function () {
    config()->set('telephony.agent.endpoint', 'PJSIP/1003');
    config()->set('telephony.agent.directory', []);
    fakeNumberDirectory();
    Queue::fake();
});

/** Every `calls` row in the system, read across the client wall. */
function allCalls(): Collection
{
    return TenantContext::cross(fn () => Call::query()->get());
}

it('writes an abandoned record when a waiting caller gives up (QD-6)', function () {
    $tenant = Tenant::factory()->create();
    fakeAgentRouter(null);   // nobody free — the caller waits

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('startHoldMusic')->once();

    $switchboard = new Switchboard($telephony);
    $flow = new CallToAgentFlow($telephony, $switchboard);
    $flow->handle(stasisStart('caller-leg', [], '9998887777', (string) $tenant->id));
    $ticket = $flow->ticketNumber();   // read before teardown — a spent handler forgets it

    $this->travel(45)->seconds();
    $flow->handle(channelDestroyed('caller-leg'));   // they hung up while holding

    $call = allCalls()->sole();

    expect($call->tenant_id)->toBe($tenant->id)
        ->and($call->direction)->toBe(CallDirection::Inbound)
        ->and($call->outcome)->toBe(CallOutcome::Abandoned)
        ->and($call->from_number)->toBe('9998887777')
        ->and($call->to_number)->toBe(dialledNumberForTenant($tenant->id))
        ->and($call->duration_seconds)->toBe(45)
        ->and($call->agent_id)->toBeNull()          // nobody handled it — that is the point
        ->and($call->correlation_id)->toBe($ticket);
});

it('writes a no-answer record when we stop waiting on the caller\'s behalf (QD-6)', function () {
    $tenant = Tenant::factory()->create(['max_hold_seconds' => 60]);
    fakeAgentRouter(null);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('startHoldMusic')->once();
    $telephony->shouldReceive('hangup')->once()->with('caller-leg');

    $switchboard = new Switchboard($telephony);
    $flow = new CallToAgentFlow($telephony, $switchboard);
    $flow->handle(stasisStart('caller-leg', [], '9998887777', (string) $tenant->id));

    $this->travel(61)->seconds();   // past THIS client's own 60-second cap, not the default
    $flow->tryAgain();

    expect(allCalls()->sole()->outcome)->toBe(CallOutcome::NoAnswer);
});

it('records a caller who gives up while the agent\'s phone is still ringing', function () {
    $tenant = Tenant::factory()->create();
    // A real agent, because ringing one writes a handoff note against their user id.
    fakeAgentRouter(clientUserWithRole($tenant, 'agent')->id);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('placeCall')->once()->andReturn('agent-leg');
    $telephony->shouldReceive('hangup')->once()->with('agent-leg');

    $switchboard = new Switchboard($telephony);
    $flow = new CallToAgentFlow($telephony, $switchboard);
    $flow->handle(stasisStart('caller-leg', [], '9998887777', (string) $tenant->id));
    $flow->handle(channelDestroyed('caller-leg'));   // gone before anyone picked up

    // They never reached a person either, so they are just as lost as one who held —
    // and this is the path that leaves no trace at all if it is not written here.
    expect(allCalls()->sole()->outcome)->toBe(CallOutcome::Abandoned);
});

it('writes nothing for a call that reached an agent — the wrap-up owns that row', function () {
    $tenant = Tenant::factory()->create();
    fakeAgentRouter(clientUserWithRole($tenant, 'agent')->id);
    $session = new RecordingSession('caller-leg', 'call-1', 'said', 'heard');

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('placeCall')->once()->andReturn('agent-leg');
    $telephony->shouldReceive('join')->once()->andReturn('conv-1');
    $telephony->shouldReceive('startRecording')->once()->andReturn($session);
    $telephony->shouldReceive('stopRecording')->once();
    $telephony->shouldReceive('hangup')->once()->with('agent-leg');
    $telephony->shouldReceive('endConversation')->once();

    $switchboard = new Switchboard($telephony);
    $flow = new CallToAgentFlow($telephony, $switchboard);
    $flow->handle(stasisStart('caller-leg', [], null, (string) $tenant->id));
    $flow->handle(stasisStart('agent-leg', ['agent']));   // answered — a real conversation
    $flow->handle(channelDestroyed('caller-leg'));

    expect(allCalls())->toHaveCount(0);
});

it('writes nothing when the dialled number belongs to no client (ND-4 — a row must have an owner)', function () {
    fakeAgentRouter(6);   // would hand back an agent, but the board is never read without an owner

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('hangup')->once()->with('caller-leg');

    $switchboard = new Switchboard($telephony);
    (new CallToAgentFlow($telephony, $switchboard))->handle(stasisStart('caller-leg', [], null, null));

    expect(allCalls())->toHaveCount(0);
});

it('never lets a failed record write disturb the call itself', function () {
    $tenant = Tenant::factory()->create();
    fakeAgentRouter(null);

    // The database refuses the row — the call must not notice.
    Call::creating(fn () => throw new RuntimeException('the database is having a moment'));

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('startHoldMusic')->once();

    $switchboard = new Switchboard($telephony);
    $switchboard->handle(stasisStart('caller-leg', [], null, (string) $tenant->id));
    $switchboard->handle(channelDestroyed('caller-leg'));

    expect(allCalls())->toHaveCount(0)                  // no row, as forced
        ->and($switchboard->activeCallCount())->toBe(0);   // and the call still ended cleanly
});
