<?php

use App\Jobs\MergeCallRecordingJob;
use App\Telephony\AriConnectionLost;
use App\Telephony\Flows\Switchboard;
use App\Telephony\RecordingSession;
use App\Telephony\TelephonyProvider;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(fn () => fakeAgentDirectory());   // SEC-1 slice 4: the reserved agent has a phone

/**
 * The B2.1 foundation: the switchboard holds MANY calls at once and keeps them apart.
 * These feed the switchboard the same raw event shapes the listener reads off ARI
 * (built by the helpers in tests/Pest.php) and prove the guarantees that make a second
 * caller safe — independent routing, failure isolation, a recording surviving its
 * call's teardown, and the line-dropped exception still bubbling up. The per-call
 * driving (answer/ring/join/record/teardown) is proven against a mocked provider in
 * CallToAgentFlowTest; here we prove the multiplexing on top of it.
 */
beforeEach(function () {

    // B2.2b: each per-call handler reserves a free agent before ringing. The switchboard
    // tests prove the multiplexing/isolation, not the routing DB, so the router is stubbed
    // to hand back one agent (the real reserve/release is proven in AgentRouterTest).
    fakeAgentRouter();
    fakeNumberDirectory();
    Queue::fake();
});

it('tracks two simultaneous calls independently, routing each interleaved event to the right handler', function () {
    $sessionA = new RecordingSession('caller-A', 'call-A', 'A-said', 'A-heard');
    $sessionB = new RecordingSession('caller-B', 'call-B', 'B-said', 'B-heard');

    $telephony = fakeTelephony();
    $telephony->shouldReceive('answer')->once()->with('caller-A');
    $telephony->shouldReceive('answer')->once()->with('caller-B');
    $telephony->shouldReceive('placeCall')->twice()->with('PJSIP/1003', 'agent', null, 20)->andReturn('agent-A', 'agent-B');
    // The pairings are the proof of routing: A's agent joins A's caller, never B's.
    $telephony->shouldReceive('join')->once()->with('caller-A', 'agent-A')->andReturn('conv-A');
    $telephony->shouldReceive('join')->once()->with('caller-B', 'agent-B')->andReturn('conv-B');
    $telephony->shouldReceive('startRecording')->once()->with('caller-A', Mockery::type('string'))->andReturn($sessionA);
    $telephony->shouldReceive('startRecording')->once()->with('caller-B', Mockery::type('string'))->andReturn($sessionB);
    $telephony->shouldReceive('stopRecording')->once()->with($sessionA);
    $telephony->shouldReceive('stopRecording')->once()->with($sessionB);
    $telephony->shouldReceive('hangup')->once()->with('agent-A');     // A's survivor
    $telephony->shouldReceive('hangup')->once()->with('agent-B');     // B's survivor
    $telephony->shouldReceive('endConversation')->once()->with('conv-A');
    $telephony->shouldReceive('endConversation')->once()->with('conv-B');

    $switchboard = new Switchboard($telephony);

    // Two callers arrive back-to-back, then both agents pick up — fully interleaved.
    $switchboard->handle(stasisStart('caller-A', []));
    $switchboard->handle(stasisStart('caller-B', []));
    $switchboard->handle(stasisStart('agent-A', ['agent']));
    $switchboard->handle(stasisStart('agent-B', ['agent']));

    expect($switchboard->activeCallCount())->toBe(2);

    $switchboard->handle(channelDestroyed('caller-A'));
    $switchboard->handle(channelDestroyed('caller-B'));

    expect($switchboard->activeCallCount())->toBe(0);
});

it('drives a second caller arriving mid-call instead of losing it, and leaves the first call undisturbed', function () {
    $sessionA = new RecordingSession('caller-A', 'call-A', 'A-said', 'A-heard');

    $telephony = fakeTelephony();
    $telephony->shouldReceive('answer')->once()->with('caller-A');
    $telephony->shouldReceive('answer')->once()->with('caller-B');   // the OLD one-handler code never answered B
    $telephony->shouldReceive('placeCall')->twice()->with('PJSIP/1003', 'agent', null, 20)->andReturn('agent-A', 'agent-B');
    $telephony->shouldReceive('join')->once()->with('caller-A', 'agent-A')->andReturn('conv-A');
    $telephony->shouldReceive('startRecording')->once()->with('caller-A', Mockery::type('string'))->andReturn($sessionA);
    $telephony->shouldReceive('stopRecording')->once()->with($sessionA);
    $telephony->shouldReceive('hangup')->once()->with('agent-A');
    $telephony->shouldReceive('endConversation')->once()->with('conv-A');

    $switchboard = new Switchboard($telephony);

    $switchboard->handle(stasisStart('caller-A', []));
    $switchboard->handle(stasisStart('agent-A', ['agent']));    // A connected, recording
    $switchboard->handle(stasisStart('caller-B', []));          // B dials in mid-call → its own handler

    expect($switchboard->activeCallCount())->toBe(2);

    $switchboard->handle(channelDestroyed('caller-A'));         // A hangs up — tears down with its own legs

    expect($switchboard->activeCallCount())->toBe(1);           // B (still ringing) is untouched
});

it('tears down only the failing call when one hits an unexpected error, keeping the others running (FD-6 backstop)', function () {
    $sessionA = new RecordingSession('caller-A', 'call-A', 'A-said', 'A-heard');

    $telephony = fakeTelephony();
    $telephony->shouldReceive('answer')->once()->with('caller-A');
    $telephony->shouldReceive('answer')->once()->with('caller-B');
    // A places fine; B's place throws an UNEXPECTED error (not a TelephonyException), so it
    // escapes the handler's own catch and hits the switchboard backstop. The differing caller
    // number is only there to tell the two placeCall expectations apart.
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent', null, 20)->andReturn('agent-A');
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent', '555', 20)->andThrow(new RuntimeException('boom'));
    $telephony->shouldReceive('join')->once()->with('caller-A', 'agent-A')->andReturn('conv-A');
    $telephony->shouldReceive('startRecording')->once()->with('caller-A', Mockery::type('string'))->andReturn($sessionA);
    $telephony->shouldReceive('hangup')->once()->with('caller-B');    // the backstop drops B's one held leg
    $telephony->shouldReceive('stopRecording')->once()->with($sessionA);
    $telephony->shouldReceive('hangup')->once()->with('agent-A');     // A still tears down cleanly afterwards
    $telephony->shouldReceive('endConversation')->once()->with('conv-A');

    $switchboard = new Switchboard($telephony);

    $switchboard->handle(stasisStart('caller-A', []));
    $switchboard->handle(stasisStart('agent-A', ['agent']));    // A connected
    $switchboard->handle(stasisStart('caller-B', [], '555'));   // B blows up → backstop disposes only B

    expect($switchboard->activeCallCount())->toBe(1);           // only A remains

    $switchboard->handle(channelDestroyed('caller-A'));         // A is fine — untouched by B's failure

    expect($switchboard->activeCallCount())->toBe(0);
});

it('lets a dropped-line exception bubble up instead of swallowing it in the backstop (FD-6)', function () {
    $telephony = fakeTelephony();
    $telephony->shouldReceive('answer')->once()->with('caller-A')->andThrow(new AriConnectionLost('pipe gone'));

    $switchboard = new Switchboard($telephony);

    // AriConnectionLost is everyone's problem (it drives the listener's reconnect), so the
    // per-call backstop must re-throw it, not treat it as one call's failure.
    expect(fn () => $switchboard->handle(stasisStart('caller-A', [])))
        ->toThrow(AriConnectionLost::class);
});

it('still merges a recording that finishes after its call handler is disposed (FD-4)', function () {
    $session = new RecordingSession('caller-A', 'call-A', 'A-said', 'A-heard');

    $telephony = fakeTelephony();
    $telephony->shouldReceive('answer')->once()->with('caller-A');
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent', null, 20)->andReturn('agent-A');
    $telephony->shouldReceive('join')->once()->with('caller-A', 'agent-A')->andReturn('conv-A');
    $telephony->shouldReceive('startRecording')->once()->with('caller-A', Mockery::type('string'))->andReturn($session);
    $telephony->shouldReceive('stopRecording')->once()->with($session);
    $telephony->shouldReceive('hangup')->once()->with('agent-A');
    $telephony->shouldReceive('endConversation')->once()->with('conv-A');

    $switchboard = new Switchboard($telephony);

    $switchboard->handle(stasisStart('caller-A', []));
    $switchboard->handle(stasisStart('agent-A', ['agent']));
    $switchboard->handle(channelDestroyed('caller-A'));         // hang-up → merge deposited → handler disposed

    expect($switchboard->activeCallCount())->toBe(0);
    Queue::assertNothingPushed();                               // not until both files confirm

    // The recording confirms LATE, after the handler is gone — the switchboard's notebook carries it.
    $switchboard->handle(recordingFinished('call-A-said'));
    $switchboard->handle(recordingFinished('call-A-heard'));

    Queue::assertPushed(
        MergeCallRecordingJob::class,
        // TH-4: the inbound recording's id is now the call's ticket (a UUID minted in
        // beginCall), not the raw caller-leg id — so the stapler's UUID guard passes it.
        fn (MergeCallRecordingJob $job): bool => Str::isUuid($job->callId) && $job->recordingName === 'call-A',
    );
});

it('merges an inbound recording even when the finished events arrive BEFORE teardown (the CP-B2.1 race)', function () {
    // The bug CP-B2.1 exposed: on an inbound call the recorded (caller) leg hangs up first,
    // so Asterisk emits "recording finished" for both taps IMMEDIATELY — before the caller's
    // ChannelDestroyed reaches us. The merge must already be registered (at connect, not at
    // teardown) or those events land on an empty notebook and the recording is lost.
    $session = new RecordingSession('caller-A', 'call-A', 'A-said', 'A-heard');

    $telephony = fakeTelephony();
    $telephony->shouldReceive('answer')->once()->with('caller-A');
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent', null, 20)->andReturn('agent-A');
    $telephony->shouldReceive('join')->once()->with('caller-A', 'agent-A')->andReturn('conv-A');
    $telephony->shouldReceive('startRecording')->once()->with('caller-A', Mockery::type('string'))->andReturn($session);
    $telephony->shouldReceive('stopRecording')->once()->with($session);
    $telephony->shouldReceive('hangup')->once()->with('agent-A');
    $telephony->shouldReceive('endConversation')->once()->with('conv-A');

    $switchboard = new Switchboard($telephony);

    $switchboard->handle(stasisStart('caller-A', []));
    $switchboard->handle(stasisStart('agent-A', ['agent']));     // connect → recording starts → merge registered NOW

    // The finished events arrive BEFORE the call's ChannelDestroyed (the inbound order):
    $switchboard->handle(recordingFinished('call-A-said'));
    $switchboard->handle(recordingFinished('call-A-heard'));

    Queue::assertPushed(                                          // merge still fires despite the early events
        MergeCallRecordingJob::class,
        // TH-4: the inbound recording's id is now the call's ticket (a UUID), not the leg id.
        fn (MergeCallRecordingJob $job): bool => Str::isUuid($job->callId) && $job->recordingName === 'call-A',
    );

    $switchboard->handle(channelDestroyed('caller-A'));          // teardown lands after — clean
    expect($switchboard->activeCallCount())->toBe(0);
});

it('never makes a handler for a snoop leg and never routes its events', function () {
    $telephony = fakeTelephony();
    $telephony->shouldNotReceive('answer');
    $telephony->shouldNotReceive('placeCall');

    $switchboard = new Switchboard($telephony);

    $switchboard->handle(stasisStart('snoop-leg', ['snoop']));  // our recording tap — infrastructure
    $switchboard->handle(channelDestroyed('snoop-leg'));        // never in the phone-book → dropped

    expect($switchboard->activeCallCount())->toBe(0);
});

it('forgets a disposed call legs, so a later event for a dead leg matches nothing and is dropped', function () {
    $session = new RecordingSession('caller-A', 'call-A', 'A-said', 'A-heard');

    $telephony = fakeTelephony();
    $telephony->shouldReceive('answer')->once()->with('caller-A');
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent', null, 20)->andReturn('agent-A');
    $telephony->shouldReceive('join')->once()->with('caller-A', 'agent-A')->andReturn('conv-A');
    $telephony->shouldReceive('startRecording')->once()->with('caller-A', Mockery::type('string'))->andReturn($session);
    $telephony->shouldReceive('stopRecording')->once()->with($session);
    $telephony->shouldReceive('hangup')->once()->with('agent-A');   // exactly once — the teardown survivor hangup
    $telephony->shouldReceive('endConversation')->once()->with('conv-A');

    $switchboard = new Switchboard($telephony);

    $switchboard->handle(stasisStart('caller-A', []));
    $switchboard->handle(stasisStart('agent-A', ['agent']));
    expect($switchboard->activeCallCount())->toBe(1);

    $switchboard->handle(channelDestroyed('caller-A'));         // teardown → legs deregistered
    expect($switchboard->activeCallCount())->toBe(0);

    // The survivor's own ChannelDestroyed arrives late, after disposal — it must match nothing
    // (no second hangup, no error). hangup('agent-A') stays at exactly once above.
    $switchboard->handle(channelDestroyed('agent-A'));
    expect($switchboard->activeCallCount())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| B2.4a — the web's transfer signal (ChannelUserevent) routing (TD-4 / TD-7)
|--------------------------------------------------------------------------
|
| The switchboard learns one new event type: the web's control signal. It names
| which AGENT it is about (their user id) + the company; the switchboard finds
| that agent's live call by a scan of live handlers (the general lookup TD-7
| reuses) and begins the transfer on THAT call, never a sibling. Two outbound
| calls give two distinct serving agents (the id rides args[3]); the signal for
| one must touch only that one.
*/

it('routes a transfer user-event to the handler serving that agent user id, leaving the other call untouched', function () {
    config()->set('telephony.outbound.dial_prefix', 'PJSIP/');
    config()->set('telephony.outbound.caller_id', '1800555000');
    fakeAgentRouter(9);   // the transfer target B (whichever call transfers)

    $sessionSix = new RecordingSession('cust-6', 'call-6', '6-said', '6-heard');
    $sessionSeven = new RecordingSession('cust-7', 'call-7', '7-said', '7-heard');

    $telephony = fakeTelephony();
    // Two outbound calls connect — one served by agent 6, one by agent 7.
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/111', 'outbound', '1800555000')->andReturn('cust-6');
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/222', 'outbound', '1800555000')->andReturn('cust-7');
    $telephony->shouldReceive('join')->once()->with('cust-6', 'agent-6')->andReturn('conv-6');
    $telephony->shouldReceive('join')->once()->with('cust-7', 'agent-7')->andReturn('conv-7');
    $telephony->shouldReceive('startRecording')->once()->with('cust-6', Mockery::type('string'))->andReturn($sessionSix);
    $telephony->shouldReceive('startRecording')->once()->with('cust-7', Mockery::type('string'))->andReturn($sessionSeven);
    // The transfer fires for agent 7 ONLY: ring B, then on answer do the surgery on
    // call-7 (its conversation + its agent leg). A removeFromBridge naming conv-6 /
    // agent-6 would be an unexpected call under Mockery's strict matching — the proof
    // the signal reached the RIGHT handler.
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent')->andReturn('btleg');
    $telephony->shouldReceive('addToBridge')->once()->with('conv-7', 'btleg');
    $telephony->shouldReceive('removeFromBridge')->once()->with('conv-7', 'agent-7');
    $telephony->shouldReceive('hangup')->once()->with('agent-7');

    $switchboard = new Switchboard($telephony);

    $switchboard->handle(stasisStart('agent-6', ['agent', '111', 'uuid-6', '6']));
    $switchboard->handle(stasisStart('cust-6', ['outbound']));
    $switchboard->handle(stasisStart('agent-7', ['agent', '222', 'uuid-7', '7']));
    $switchboard->handle(stasisStart('cust-7', ['outbound']));

    expect($switchboard->activeCallCount())->toBe(2);

    $switchboard->handle(channelUserevent('transfer', ['agentUserId' => '7', 'tenantId' => '3']));
    $switchboard->handle(stasisStart('btleg', ['agent']));   // B answers -> completeTransfer on call-7
});

it('ignores a transfer user-event for an agent who has no live call', function () {
    fakeAgentRouter();
    fakeNumberDirectory();
    $session = new RecordingSession('caller-A', 'call-A', 'A-said', 'A-heard');

    $telephony = fakeTelephony();
    $telephony->shouldReceive('answer')->once()->with('caller-A');
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent', null, 20)->andReturn('agent-A');
    $telephony->shouldReceive('join')->once()->andReturn('conv-A');
    $telephony->shouldReceive('startRecording')->once()->andReturn($session);
    // No transfer placeCall — agent 99 is on no call, so nothing is rung. Under strict
    // Mockery a stray 2-arg placeCall here would fail the test.

    $switchboard = new Switchboard($telephony);
    $switchboard->handle(stasisStart('caller-A', []));
    $switchboard->handle(stasisStart('agent-A', ['agent']));   // serving agent = 6

    $switchboard->handle(channelUserevent('transfer', ['agentUserId' => '99', 'tenantId' => '3']));

    expect($switchboard->activeCallCount())->toBe(1);   // call A undisturbed
});

/*
|--------------------------------------------------------------------------
| B2.4b-i — the web's conference signal (ChannelUserevent) routing (CD-3/CD-6)
|--------------------------------------------------------------------------
|
| Conference reuses the SAME signal plumbing as transfer — only the eventname differs
| ('conference' vs 'transfer'). The switchboard finds the handler serving that agent by
| the same general scan and begins the conference on THAT call. The proof it forks
| correctly: addToBridge fires and removeFromBridge does NOT (the existing agent is kept,
| unlike a transfer). After the join, isServingAgent is true for BOTH agents (CD-2 set
| membership), so a second conference click — or supervisor-monitor later — reaches it
| by either one.
*/

it('routes a conference user-event to the handler serving that agent, adding B WITHOUT dropping A', function () {
    config()->set('telephony.outbound.dial_prefix', 'PJSIP/');
    config()->set('telephony.outbound.caller_id', '1800555000');
    fakeAgentRouter(9);   // the conference target B

    $sessionSix = new RecordingSession('cust-6', 'call-6', '6-said', '6-heard');
    $sessionSeven = new RecordingSession('cust-7', 'call-7', '7-said', '7-heard');

    $telephony = fakeTelephony();
    // Two outbound calls connect — one served by agent 6, one by agent 7 (the threaded id).
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/111', 'outbound', '1800555000')->andReturn('cust-6');
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/222', 'outbound', '1800555000')->andReturn('cust-7');
    $telephony->shouldReceive('join')->once()->with('cust-6', 'agent-6')->andReturn('conv-6');
    $telephony->shouldReceive('join')->once()->with('cust-7', 'agent-7')->andReturn('conv-7');
    $telephony->shouldReceive('startRecording')->once()->with('cust-6', Mockery::type('string'))->andReturn($sessionSix);
    $telephony->shouldReceive('startRecording')->once()->with('cust-7', Mockery::type('string'))->andReturn($sessionSeven);
    // The conference fires for agent 7 ONLY: ring B, then on answer ADD B to call-7's
    // conversation and KEEP agent 7 — no removeFromBridge anywhere (that would be a transfer,
    // and naming conv-6 would be the wrong handler). Both are the proof of correct routing + fork.
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent')->andReturn('bcleg');
    $telephony->shouldReceive('addToBridge')->once()->with('conv-7', 'bcleg');
    $telephony->shouldNotReceive('removeFromBridge');

    $switchboard = new Switchboard($telephony);

    $switchboard->handle(stasisStart('agent-6', ['agent', '111', 'uuid-6', '6']));
    $switchboard->handle(stasisStart('cust-6', ['outbound']));
    $switchboard->handle(stasisStart('agent-7', ['agent', '222', 'uuid-7', '7']));
    $switchboard->handle(stasisStart('cust-7', ['outbound']));

    expect($switchboard->activeCallCount())->toBe(2);

    $switchboard->handle(channelUserevent('conference', ['agentUserId' => '7', 'tenantId' => '3']));
    $switchboard->handle(stasisStart('bcleg', ['agent']));   // B answers -> completeConference on call-7

    expect($switchboard->activeCallCount())->toBe(2);   // both calls still live; call-6 untouched
});

it('ignores a conference user-event for an agent who has no live call', function () {
    fakeAgentRouter();
    fakeNumberDirectory();
    $session = new RecordingSession('caller-A', 'call-A', 'A-said', 'A-heard');

    $telephony = fakeTelephony();
    $telephony->shouldReceive('answer')->once()->with('caller-A');
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent', null, 20)->andReturn('agent-A');
    $telephony->shouldReceive('join')->once()->andReturn('conv-A');
    $telephony->shouldReceive('startRecording')->once()->andReturn($session);
    // No conference placeCall — agent 99 is on no call, so nothing is rung.

    $switchboard = new Switchboard($telephony);
    $switchboard->handle(stasisStart('caller-A', []));
    $switchboard->handle(stasisStart('agent-A', ['agent']));   // serving agent = 6

    $switchboard->handle(channelUserevent('conference', ['agentUserId' => '99', 'tenantId' => '3']));

    expect($switchboard->activeCallCount())->toBe(1);   // call A undisturbed
});

/*
| B2.3b-i QD-3 — the waiting-room sweep. The listener calls sweepWaiting() on every
| pass of its loop; the switchboard walks its live calls in arrival order and asks each
| waiting one to try again. The per-call decision (retry / give up / stay) lives in
| CallToAgentFlowWaitingRoomTest; here we prove the multiplexing: who gets asked, in
| what order, and that a call nobody is waiting on is untouched.
*/

it('hands the freed desk to the caller who has waited longest (QD-3 arrival order)', function () {
    $router = fakeAgentRouter(null);   // the floor is full, so both callers wait

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-first');
    $telephony->shouldReceive('answer')->once()->with('caller-second');
    $telephony->shouldReceive('startHoldMusic')->once()->with('caller-first');
    $telephony->shouldReceive('startHoldMusic')->once()->with('caller-second');

    $switchboard = new Switchboard($telephony);
    $switchboard->handle(stasisStart('caller-first', []));
    $switchboard->handle(stasisStart('caller-second', []));

    expect($switchboard->activeCallCount())->toBe(2);

    // One desk frees up. Exactly one caller may have it, and it must be the first one in.
    // Neither caller's music stops here (S88 review #3): the first one's keeps playing
    // through the ring, because they are not on that agent's line until it is answered.
    $router->agentId = 6;
    $telephony->shouldNotReceive('stopHoldMusic');
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent', null, 20)
        ->andReturnUsing(function () use ($router): string {
            $router->agentId = null;   // that agent is now taken — the floor is full again

            return 'agent-leg';
        });

    $switchboard->sweepWaiting();

    expect($switchboard->activeCallCount())->toBe(2);   // the second caller is still holding, not dropped
});

it('leaves a call that is not waiting completely alone during a sweep', function () {
    fakeAgentRouter(6);
    $session = new RecordingSession('caller-A', 'call-A', 'A-said', 'A-heard');

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-A');
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent', null, 20)->andReturn('agent-A');
    $telephony->shouldReceive('join')->once()->andReturn('conv-A');
    $telephony->shouldReceive('startRecording')->once()->andReturn($session);
    // Music starts once for the ring and stops once when the two are joined (S88); the
    // sweeps below add nothing to either count, which is this test's whole point.
    $telephony->shouldReceive('startHoldMusic')->once();
    $telephony->shouldReceive('stopHoldMusic')->once();
    $telephony->shouldNotReceive('hangup');

    $switchboard = new Switchboard($telephony);
    $switchboard->handle(stasisStart('caller-A', []));
    $switchboard->handle(stasisStart('agent-A', ['agent']));   // a live, connected call

    $switchboard->sweepWaiting();
    $switchboard->sweepWaiting();

    expect($switchboard->activeCallCount())->toBe(1);
});

it('sweeps an empty switchboard without complaint', function () {
    $telephony = fakeTelephony();

    $switchboard = new Switchboard($telephony);
    $switchboard->sweepWaiting();

    expect($switchboard->activeCallCount())->toBe(0);
});

/**
 * 🔴 DIAL-1 R8 — a leg WE placed, arriving at a switchboard that has lost its handler.
 * That is what a listener restart mid-call looks like from the other side: Asterisk keeps
 * the channels when our connection drops (it deactivates the application and reactivates
 * it on reconnect), so the customer's phone is still ringing and the new process has no
 * memory of why. Dropping the arrival, which is what used to happen, leaves whoever
 * answers in silence — no music, no agent, no hangup — until they give up.
 *
 * Never dialer-only: an inbound ring's `agent` leg and the console's `outbound` leg orphan
 * exactly the same way. The dialer is what makes it one per campaign per second.
 */
it('ends a leg we placed when its handler is gone, instead of leaving the line silent', function (string $tag) {
    $telephony = fakeTelephony();
    $telephony->shouldReceive('hangup')->once()->with('orphan-leg');
    // 🔴 And makes no attempt to serve it: nothing in this process can. Its client, lead,
    // booked desk and ticket all died with the handler.
    $telephony->shouldNotReceive('placeCall');
    $telephony->shouldNotReceive('answer');

    $switchboard = new Switchboard($telephony);
    $switchboard->handle(stasisStart('orphan-leg', [$tag]));

    expect($switchboard->activeCallCount())->toBe(0);
})->with(['dialer', 'agent', 'outbound']);

/**
 * The other side of the same coin: a tag that is not one of ours is left alone. Ending a
 * channel some other application put in this app would be us reaching outside our own
 * calls, which is a worse habit than a dropped event.
 */
it('leaves a leg alone when the tag is not one we place', function () {
    $telephony = fakeTelephony();
    $telephony->shouldNotReceive('hangup');

    (new Switchboard($telephony))->handle(stasisStart('somebody-elses-leg', ['weather-line']));
});

/**
 * 🔴 The deploy path (R8). Asked to stop, the switchboard ends what it is holding rather
 * than leaving it for the next process to find. Without this a dial in flight rings on,
 * the customer says hello, and the NEXT listener hangs up on them — an answered call ended
 * in a second, which is exactly what DP-12a counts against the 3% legal cap. Ending the
 * ring before anyone answers leaves nothing to count.
 */
it('ends every live call and hands back every desk when the process is asked to stop', function () {
    $telephony = fakeTelephony();
    $telephony->shouldReceive('answer')->once()->with('caller-A');
    $telephony->shouldReceive('placeCall')->once()->andReturn('agent-A');

    $switchboard = new Switchboard($telephony);
    $switchboard->handle(stasisStart('caller-A', []));

    expect($switchboard->activeCallCount())->toBe(1);

    // Both legs go: the caller's and the desk phone still ringing for them.
    $telephony->shouldReceive('hangup')->once()->with('caller-A');
    $telephony->shouldReceive('hangup')->once()->with('agent-A');

    $switchboard->drain();

    expect($switchboard->activeCallCount())->toBe(0)
        ->and($switchboard->heldDesksByTenant())->toBe([]);
});

it('drains an empty switchboard without complaint', function () {
    $telephony = fakeTelephony();
    $telephony->shouldNotReceive('hangup');

    (new Switchboard($telephony))->drain();

    expect(true)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| SM slice 2 — the web's listen-in signal (ChannelUserevent) routing
|--------------------------------------------------------------------------
*/

it('routes a listen signal to the call the named agent is on, and to no other', function () {
    config()->set('telephony.outbound.dial_prefix', 'PJSIP/');
    config()->set('telephony.outbound.caller_id', '1800555000');
    $sessionA = new RecordingSession('caller-A', 'call-A', 'A-said', 'A-heard');
    $sessionB = new RecordingSession('caller-B', 'call-B', 'B-said', 'B-heard');

    $telephony = fakeTelephony();
    // Two outbound calls — one served by agent 6, one by agent 7.
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/111', 'outbound', '1800555000')->andReturn('caller-A');
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/222', 'outbound', '1800555000')->andReturn('caller-B');
    $telephony->shouldReceive('join')->once()->with('caller-A', 'agent-A')->andReturn('conv-A');
    $telephony->shouldReceive('join')->once()->with('caller-B', 'agent-B')->andReturn('conv-B');
    $telephony->shouldReceive('startRecording')->once()->with('caller-A', Mockery::type('string'))->andReturn($sessionA);
    $telephony->shouldReceive('startRecording')->once()->with('caller-B', Mockery::type('string'))->andReturn($sessionB);

    // The listen fires for agent 7 ONLY. A tap naming agent-A would be an unexpected
    // call under Mockery's strict matching — the proof the signal reached the RIGHT
    // handler, exactly as the transfer case above proves it for its own signal.
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'monitor')->andReturn('monitor-leg');
    $telephony->shouldReceive('snoop')->once()->with('agent-B', 'both')->andReturn('tap-leg');
    $telephony->shouldReceive('join')->once()->with('tap-leg', 'monitor-leg')->andReturn('monitor-conv');

    $switchboard = new Switchboard($telephony);

    $switchboard->handle(stasisStart('agent-A', ['agent', '111', 'uuid-A', '6']));
    $switchboard->handle(stasisStart('caller-A', ['outbound']));
    $switchboard->handle(stasisStart('agent-B', ['agent', '222', 'uuid-B', '7']));
    $switchboard->handle(stasisStart('caller-B', ['outbound']));

    $switchboard->handle(channelUserevent('listen', ['agentUserId' => '7', 'supervisorUserId' => '2']));
    $switchboard->handle(stasisStart('monitor-leg', ['monitor']));   // the supervisor picks up

    expect($switchboard->activeCallCount())->toBe(2);   // still two calls; a listener is not one
});

it('ignores a listen signal that does not say who is listening', function () {
    fakeNumberDirectory();
    fakeAgentRouter();
    $session = new RecordingSession('caller-A', 'call-A', 'A-said', 'A-heard');

    $telephony = fakeTelephony();
    $telephony->shouldReceive('answer')->once()->with('caller-A');
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent', null, 20)->andReturn('agent-A');
    $telephony->shouldReceive('join')->once()->andReturn('conv-A');
    $telephony->shouldReceive('startRecording')->once()->andReturn($session);
    // Nothing is rung and nothing is tapped. The supervisor is derived from web auth on
    // the page that sent the signal, so a signal without one is malformed, not a request
    // to listen as nobody.
    $telephony->shouldNotReceive('snoop');

    $switchboard = new Switchboard($telephony);
    $switchboard->handle(stasisStart('caller-A', []));
    $switchboard->handle(stasisStart('agent-A', ['agent']));

    $switchboard->handle(channelUserevent('listen', ['agentUserId' => '6']));

    expect($switchboard->activeCallCount())->toBe(1);
});
