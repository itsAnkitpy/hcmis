<?php

use App\Models\CallHandoff;
use App\Models\Tenant;
use App\Telephony\Flows\CallToAgentFlow;
use App\Telephony\Flows\Switchboard;
use App\Telephony\RecordingSession;
use App\Telephony\TelephonyProvider;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery\MockInterface;

/**
 * Hold — the operator's half (PRD/phase-2/hold.md).
 *
 * The agent presses Hold, the caller's line leaves the conversation and music starts on
 * it; Resume walks them back. How long they were held rides the pigeonhole note to the
 * agent's screen, exactly as the four timing moments already do (H-4).
 *
 * The console's half — copying the total onto the call row — is in
 * tests/Feature/Filament/AgentConsoleHoldTest.php.
 *
 * DB-backed, like the other note tests: the write is the thing under test, so it needs a
 * real client and a real reserved agent for the foreign key and the row-level wall.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => fakeAgentDirectory());   // SEC-1 slice 4: the reserved agent has a phone

afterEach(function () {
    TenantContext::forget();
});

beforeEach(function () {
    fakeNumberDirectory();
    Queue::fake();
});

/**
 * A client with one agent the board hands back on every reservation.
 *
 * @return array{0: Tenant, 1: int}
 */
function heldCallTenant(): array
{
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, 'agent');
    fakeAgentRouter($agent->id);

    return [$tenant, $agent->id];
}

/** One agent's note on this call. */
function holdNoteFor(Tenant $tenant, int $agentId): ?CallHandoff
{
    return TenantContext::run(
        $tenant->id,
        fn (): ?CallHandoff => CallHandoff::query()->where('agent_user_id', $agentId)->first(),
    );
}

/**
 * A mocked phone system already expecting an inbound call answered by one agent. The
 * hold verbs are left to each test, because they are what each test is about.
 *
 * @return TelephonyProvider&MockInterface
 */
function telephonyOnACall(): MockInterface
{
    $telephony = fakeTelephony();
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent', null, 20)->andReturn('agent-leg');
    $telephony->shouldReceive('join')->once()->andReturn('conv-1');
    $telephony->shouldReceive('startRecording')->once()
        ->andReturn(new RecordingSession('caller-leg', 'call-1', 'snoop-said', 'snoop-heard'));

    return $telephony;
}

/** Drive that mocked call to the point an agent is on the line. */
function callInProgress(MockInterface $telephony, Tenant $tenant): CallToAgentFlow
{
    $flow = new CallToAgentFlow($telephony, new Switchboard($telephony));
    $flow->handle(stasisStart('caller-leg', [], null, (string) $tenant->id));
    $flow->handle(stasisStart('agent-leg', ['agent']));

    return $flow;
}

it('parks the caller with music on and puts them back on resume, storing the seconds they were held', function () {
    [$tenant, $agentId] = heldCallTenant();

    // A strict mock rather than the shared one, because THIS test is about the music:
    // the caller hears it from the moment the hold lands until the moment it does not.
    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('placeCall')->once()->andReturn('agent-leg');
    $telephony->shouldReceive('join')->once()->andReturn('conv-1');
    $telephony->shouldReceive('startRecording')->once()
        ->andReturn(new RecordingSession('caller-leg', 'call-1', 'snoop-said', 'snoop-heard'));
    // The music the waiting room already owns, on the caller's own line, both ways —
    // the ring's own copy stops when the agent picks up, so these two are the hold's.
    $telephony->shouldReceive('startHoldMusic')->twice()->with('caller-leg');
    $telephony->shouldReceive('stopHoldMusic')->twice()->with('caller-leg');
    // H-2: the caller's line leaves the conversation and comes back. The agent's line
    // never moves, and neither does the recording, which sits on the caller's line.
    $telephony->shouldReceive('removeFromBridge')->once()->with('conv-1', 'caller-leg');
    $telephony->shouldReceive('addToBridge')->once()->with('conv-1', 'caller-leg');

    $flow = callInProgress($telephony, $tenant);

    $flow->beginHold($agentId);
    $this->travel(90)->seconds();
    $flow->resumeHold();

    expect(holdNoteFor($tenant, $agentId)->hold_seconds)->toBe(90);
});

// H-1, and the reason the column is a total rather than a pair of moments: one call can
// be held three times, so there is no single pair to store.
it('adds two holds on one call into one total', function () {
    [$tenant, $agentId] = heldCallTenant();

    $telephony = telephonyOnACall();
    $telephony->shouldReceive('removeFromBridge')->twice()->with('conv-1', 'caller-leg');
    $telephony->shouldReceive('addToBridge')->twice()->with('conv-1', 'caller-leg');

    $flow = callInProgress($telephony, $tenant);

    $flow->beginHold($agentId);
    $this->travel(30)->seconds();
    $flow->resumeHold();

    $this->travel(2)->minutes();   // they talk for a while

    $flow->beginHold($agentId);
    $this->travel(45)->seconds();
    $flow->resumeHold();

    expect(holdNoteFor($tenant, $agentId)->hold_seconds)->toBe(75);
});

// H-8, first of the four endings: the caller puts the phone down while still on hold.
// Their own line ending already runs the teardown; the hold is closed on the way in.
it('closes the hold and stores the total when the caller hangs up while held', function () {
    [$tenant, $agentId] = heldCallTenant();

    $telephony = telephonyOnACall();
    $telephony->shouldReceive('removeFromBridge')->once()->with('conv-1', 'caller-leg');
    $telephony->shouldReceive('stopRecording')->once();
    $telephony->shouldReceive('hangup')->once()->with('agent-leg');
    $telephony->shouldReceive('endConversation')->once();

    $flow = callInProgress($telephony, $tenant);

    $flow->beginHold($agentId);
    $this->travel(45)->seconds();
    $flow->handle(channelDestroyed('caller-leg'));

    $note = holdNoteFor($tenant, $agentId);

    expect($note->hold_seconds)->toBe(45)
        // The call still ends properly: the hang-up moment is stamped as it always was.
        ->and($note->ended_at)->not->toBeNull();
});

// H-8, second ending: the agent puts the phone down with the caller still parked.
it('closes the hold and stores the total when the agent hangs up while held', function () {
    [$tenant, $agentId] = heldCallTenant();

    $telephony = telephonyOnACall();
    $telephony->shouldReceive('removeFromBridge')->once()->with('conv-1', 'caller-leg');
    $telephony->shouldReceive('stopRecording')->once();
    $telephony->shouldReceive('hangup')->once()->with('caller-leg');
    $telephony->shouldReceive('endConversation')->once();

    $flow = callInProgress($telephony, $tenant);

    $flow->beginHold($agentId);
    $this->travel(20)->seconds();
    $flow->handle(channelDestroyed('agent-leg'));

    expect(holdNoteFor($tenant, $agentId)->hold_seconds)->toBe(20);
});

// H-9. Ringing a second agent needs the caller IN the conversation, because the promise
// both features rest on is that the caller is never left alone.
it('refuses a transfer while the caller is held, and leaves the call exactly as it was', function () {
    [$tenant, $agentId] = heldCallTenant();
    $router = fakeAgentRouter($agentId);

    $telephony = telephonyOnACall();
    $telephony->shouldReceive('removeFromBridge')->once()->with('conv-1', 'caller-leg');
    $telephony->shouldReceive('addToBridge')->once()->with('conv-1', 'caller-leg');
    // Nothing is rung. Under strict matching a second, two-argument placeCall here would
    // fail the test on its own — which is the proof the refusal happened before the ring.
    $telephony->shouldReceive('stopRecording')->once();
    $telephony->shouldReceive('hangup')->once()->with('agent-leg');
    $telephony->shouldReceive('endConversation')->once();

    $flow = callInProgress($telephony, $tenant);

    $flow->beginHold($agentId);
    $flow->beginTransfer($tenant->id);

    // Not even a reservation: the guard sits above the board read, so nobody is booked
    // and then let go again.
    expect($router->reserved)->toBe([$tenant->id])
        ->and($flow->isServingAgent($agentId))->toBeTrue();

    // And the call carries on normally afterwards.
    $flow->resumeHold();
    $flow->handle(channelDestroyed('caller-leg'));
});

// H-12. Two agents are conferenced with the caller and one presses Hold; the two agents
// are left talking to each other, which is the main reason a floor uses hold at all.
it('puts a three-way hold on the note of the agent who pressed it, and not the other one', function () {
    $tenant = Tenant::factory()->create();
    $priya = clientUserWithRole($tenant, 'agent');
    $rahul = clientUserWithRole($tenant, 'agent');
    $router = fakeAgentRouter($priya->id);

    $telephony = telephonyOnACall();
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent')->andReturn('conf-leg');
    $telephony->shouldReceive('addToBridge')->once()->with('conv-1', 'conf-leg');
    $telephony->shouldReceive('removeFromBridge')->once()->with('conv-1', 'caller-leg');
    $telephony->shouldReceive('stopRecording')->once();
    $telephony->shouldReceive('hangup')->once()->with('agent-leg');
    $telephony->shouldReceive('hangup')->once()->with('conf-leg');
    $telephony->shouldReceive('endConversation')->once();

    $flow = callInProgress($telephony, $tenant);
    $router->agentId = $rahul->id;
    $flow->beginConference($tenant->id);
    $flow->handle(stasisStart('conf-leg', ['agent']));   // Rahul joins the three-way

    $flow->beginHold($priya->id);                        // Priya parks the caller
    $this->travel(60)->seconds();
    $flow->handle(channelDestroyed('caller-leg'));       // the caller gives up and goes

    // Their rows are separate on purpose (CT-12), each carrying that agent's own part of
    // the call. One agent's decision is not the other agent's number.
    expect(holdNoteFor($tenant, $priya->id)->hold_seconds)->toBe(60)
        ->and(holdNoteFor($tenant, $rahul->id)->hold_seconds)->toBeNull();
});

// 🔴 S112 review #1. Both agents on one call press Hold, one after the other. Each note
// must carry that agent's own seconds and nobody else's — the total is kept per agent, so
// the second agent to press Hold cannot inherit the first agent's time. Before the fix
// Rahul's note read 90: his own 30 plus Priya's 60. That over-reports Held and makes
// talkedSeconds() subtract music that played during somebody else's turn.
//
// It is how every contact centre we checked stores it: Amazon Connect's
// AgentInitiatedHoldDuration, Genesys Cloud's per-segment hold, Cisco's per-leg
// Termination_Call_Detail. The whole-call figure is the two rows added up — here, 90.
it('keeps each agent hold total separate when two agents hold the same caller', function () {
    $tenant = Tenant::factory()->create();
    $priya = clientUserWithRole($tenant, 'agent');
    $rahul = clientUserWithRole($tenant, 'agent');
    $router = fakeAgentRouter($priya->id);

    $telephony = telephonyOnACall();
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent')->andReturn('conf-leg');
    $telephony->shouldReceive('addToBridge')->once()->with('conv-1', 'conf-leg');
    // Twice each: parked and put back once by Priya, once by Rahul.
    $telephony->shouldReceive('removeFromBridge')->twice()->with('conv-1', 'caller-leg');
    $telephony->shouldReceive('addToBridge')->twice()->with('conv-1', 'caller-leg');
    $telephony->shouldReceive('stopRecording')->once();
    $telephony->shouldReceive('hangup')->once()->with('agent-leg');
    $telephony->shouldReceive('hangup')->once()->with('conf-leg');
    $telephony->shouldReceive('endConversation')->once();

    $flow = callInProgress($telephony, $tenant);
    $router->agentId = $rahul->id;
    $flow->beginConference($tenant->id);
    $flow->handle(stasisStart('conf-leg', ['agent']));

    $flow->beginHold($priya->id);
    $this->travel(60)->seconds();
    $flow->resumeHold();

    $flow->beginHold($rahul->id);
    $this->travel(30)->seconds();
    $flow->resumeHold();

    $flow->handle(channelDestroyed('caller-leg'));

    expect(holdNoteFor($tenant, $priya->id)->hold_seconds)->toBe(60)
        ->and(holdNoteFor($tenant, $rahul->id)->hold_seconds)->toBe(30);
});

// 🔴 NOT in hold.md, and the never-strand rule decides it: the agent who parked the
// caller leaves a three-way while somebody else is still on the call. H-8 answers what
// happens when the call ENDS while held and does not reach this — the call does not end,
// it carries on without the person who did the parking. The remaining agent's own button
// reads "Hold", so without this the caller listens to music nobody can stop.
it('puts the caller back when the agent who held them leaves a three-way that carries on', function () {
    $tenant = Tenant::factory()->create();
    $priya = clientUserWithRole($tenant, 'agent');
    $rahul = clientUserWithRole($tenant, 'agent');
    $router = fakeAgentRouter($priya->id);

    $telephony = telephonyOnACall();
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent')->andReturn('conf-leg');
    $telephony->shouldReceive('addToBridge')->once()->with('conv-1', 'conf-leg');
    $telephony->shouldReceive('removeFromBridge')->once()->with('conv-1', 'caller-leg');
    // The caller is put back into the conversation the moment Priya's line ends.
    $telephony->shouldReceive('addToBridge')->once()->with('conv-1', 'caller-leg');
    $telephony->shouldReceive('stopRecording')->once();
    $telephony->shouldReceive('hangup')->once()->with('conf-leg');
    $telephony->shouldReceive('endConversation')->once();

    $flow = callInProgress($telephony, $tenant);
    $router->agentId = $rahul->id;
    $flow->beginConference($tenant->id);
    $flow->handle(stasisStart('conf-leg', ['agent']));

    $flow->beginHold($priya->id);
    $this->travel(15)->seconds();
    $flow->handle(channelDestroyed('agent-leg'));   // Priya hangs up; Rahul carries on

    expect(holdNoteFor($tenant, $priya->id)->hold_seconds)->toBe(15)
        ->and($flow->isServingAgent($rahul->id))->toBeTrue();

    $flow->handle(channelDestroyed('caller-leg'));
});

// 🔴 H-10 through the door the BUTTON really uses (S119 A2). Every other hold test in this
// file calls beginHold() on the flow object, which proves the parking works but never proves
// the signal reaches it: the agent lookup in Switchboard::onUserEvent(), and the backstop in
// Switchboard::guard() that tears a call DOWN when anything inside it throws. A routing slip
// between the two would leave all of them green and, live, END the call the agent tried to
// park — no error on screen, the customer simply gone. The negative twin is the test below.
it('parks and returns the caller when the Hold signal arrives the way the console sends it', function () {
    [$tenant, $agentId] = heldCallTenant();

    $telephony = telephonyOnACall();
    $telephony->shouldReceive('removeFromBridge')->once()->with('conv-1', 'caller-leg');
    $telephony->shouldReceive('addToBridge')->once()->with('conv-1', 'caller-leg');

    // Driven through the switchboard, not the flow: this is the object the live listener
    // holds, and the only one that reads a user-event off the pipe.
    $switchboard = new Switchboard($telephony);
    $switchboard->handle(stasisStart('caller-leg', [], null, (string) $tenant->id));
    $switchboard->handle(stasisStart('agent-leg', ['agent']));

    // Byte for byte what AgentConsole::holdCall() and resumeCall() put on the wire: the
    // agent's own user id and nothing else — no company, because a hold reserves nothing.
    $switchboard->handle(channelUserevent('hold', ['agentUserId' => (string) $agentId]));
    $this->travel(40)->seconds();
    $switchboard->handle(channelUserevent('resume', ['agentUserId' => (string) $agentId]));

    // Named, not counted: the seconds prove it was THIS hold that landed, and the two
    // bridge expectations above prove the caller actually left the conversation and came back.
    expect(holdNoteFor($tenant, $agentId)->hold_seconds)->toBe(40);
});

// The same posture every other signal has: a message about an agent who is on no call
// names nothing to act on, so it is dropped.
it('ignores a hold message naming an agent who is on no call', function () {
    fakeAgentRouter();

    $telephony = fakeTelephony();
    $telephony->shouldReceive('answer')->once()->with('caller-A');
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent', null, 20)->andReturn('agent-A');
    $telephony->shouldReceive('join')->once()->andReturn('conv-A');
    $telephony->shouldReceive('startRecording')->once()
        ->andReturn(new RecordingSession('caller-A', 'call-A', 'A-said', 'A-heard'));
    // No bridge surgery at all: under strict matching a removeFromBridge here would fail
    // the test, which is what proves the live call was never touched.

    $switchboard = new Switchboard($telephony);
    $switchboard->handle(stasisStart('caller-A', []));
    $switchboard->handle(stasisStart('agent-A', ['agent']));   // the serving agent is 6

    $switchboard->handle(channelUserevent('hold', ['agentUserId' => '99']));

    expect($switchboard->activeCallCount())->toBe(1);
});

// TH-2's best-effort rule, reached the way it actually happens: our own global staff dial
// out with no client in scope, so there is no note to write the total onto. The hold must
// still work on the wire — a missing figure costs one blank on one report row and must
// never cost a live call.
it('holds and resumes normally on a call that has no note to write the total onto', function () {
    $telephony = fakeTelephony();
    $telephony->shouldReceive('placeCall')->once()->andReturn('customer-leg');
    $telephony->shouldReceive('join')->once()->andReturn('conv-1');
    $telephony->shouldReceive('startRecording')->once()
        ->andReturn(new RecordingSession('customer-leg', 'call-1', 'snoop-said', 'snoop-heard'));
    $telephony->shouldReceive('removeFromBridge')->once()->with('conv-1', 'customer-leg');
    $telephony->shouldReceive('addToBridge')->once()->with('conv-1', 'customer-leg');

    $flow = new CallToAgentFlow($telephony, new Switchboard($telephony));
    // Four arguments, not five: no client in scope (CS-4's null case).
    $flow->handle(stasisStart('agent-leg', ['agent', '9991234567', (string) Str::uuid(), '6']));
    $flow->handle(stasisStart('customer-leg', ['outbound']));

    $flow->beginHold(6);
    $this->travel(10)->seconds();
    $flow->resumeHold();

    expect(TenantContext::cross(fn () => CallHandoff::query()->count()))->toBe(0);
});
