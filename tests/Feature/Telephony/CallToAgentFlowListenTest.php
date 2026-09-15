<?php

use App\Telephony\AgentDirectory;
use App\Telephony\AgentPhoneWriter;
use App\Telephony\AgentRouter;
use App\Telephony\Flows\CallToAgentFlow;
use App\Telephony\Flows\Switchboard;
use App\Telephony\RecordingSession;
use App\Telephony\TelephonyProvider;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;

/**
 * SM slice 2 — a supervisor listening in on a live call, driven event by event against
 * a mocked phone system (no Asterisk, no network).
 *
 * The one thing every case here is really asserting is that THE CALL DOES NOT MOVE.
 * A listener is not a participant: nobody on the call can hear them, the conversation's
 * membership never changes, and no state on the handler shifts. So each case pairs
 * "the monitoring worked" with "and the call was untouched", the second half enforced
 * by Mockery refusing any verb the test did not declare.
 *
 * The teardown cases are the important ones. Every way a monitoring session can end —
 * the supervisor hanging up, the call ending underneath them, the agent leaving while
 * their phone is still ringing — has to release the same three things, and a leak means
 * a tap channel and a bridge left on the switch for good.
 */
beforeEach(function () {
    fakeNumberDirectory();   // resolves the call's dialled number to its company (B2.3a)
    fakeAgentRouter();       // the inbound ring books agent 6
    Queue::fake();
});

/**
 * A directory that hands each user their OWN phone, so the supervisor's ring can be told
 * apart from the agent's on the wire. The shared fakeAgentDirectory() returns one
 * endpoint for everybody, which would make every assertion below ambiguous.
 *
 * @param  array<int, string>  $endpoints  user id => endpoint
 */
function directoryOfPhones(array $endpoints): void
{
    app()->instance(AgentDirectory::class, new class($endpoints) extends AgentDirectory
    {
        /** @param  array<int, string>  $endpoints */
        public function __construct(private readonly array $endpoints)
        {
            parent::__construct(app(AgentPhoneWriter::class));
        }

        public function endpointFor(int $userId): ?string
        {
            return $this->endpoints[$userId] ?? null;
        }
    });
}

/**
 * Drive a handler to a live inbound call: the caller is talking to agent 6, recording
 * running. The provider mock must already expect answer / placeCall / join /
 * startRecording.
 */
function liveCallWithAgentSix(TelephonyProvider $telephony): CallToAgentFlow
{
    $flow = new CallToAgentFlow($telephony, new Switchboard($telephony));

    $flow->handle(stasisStart('caller-leg', []));
    $flow->handle(stasisStart('agent-leg', ['agent']));

    return $flow;
}

/** The three verbs a live inbound call makes before anybody listens in. */
function expectLiveCall(MockInterface $telephony): RecordingSession
{
    $session = new RecordingSession('caller-leg', 'call-1', 'snoop-said', 'snoop-heard');

    $telephony->shouldReceive('answer')->once()->with('caller-leg');
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1100', 'agent', null, 20)->andReturn('agent-leg');
    $telephony->shouldReceive('join')->once()->with('caller-leg', 'agent-leg')->andReturn('conv-1');
    $telephony->shouldReceive('startRecording')->once()->with('caller-leg', Mockery::type('string'))->andReturn($session);

    return $session;
}

it('rings the supervisors own phone, then taps the AGENT leg and feeds it to them', function () {
    directoryOfPhones([6 => 'PJSIP/1100', 2 => 'PJSIP/1105']);

    $telephony = fakeTelephony();
    expectLiveCall($telephony);

    // The supervisor's own phone, tagged so its pickup routes back to this handler.
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1105', 'monitor')->andReturn('monitor-leg');
    // 🔴 The tap goes on the AGENT's line, not the customer's (SQ-6): the customer's
    // line already carries recording's two taps and the agent's carries none, so this is
    // the FIRST tap there. 'both' on the agent's line is the whole conversation.
    $telephony->shouldReceive('snoop')->once()->with('agent-leg', 'both')->andReturn('tap-leg');
    // A mixer of their own — never the call's conversation.
    $telephony->shouldReceive('join')->once()->with('tap-leg', 'monitor-leg')->andReturn('monitor-conv');

    $flow = liveCallWithAgentSix($telephony);
    $flow->beginListen(agentUserId: 6, supervisorUserId: 2);
    $flow->handle(stasisStart('monitor-leg', ['monitor']));

    // The call is exactly as it was: the agent still serves it, the supervisor never
    // becomes a participant, and nothing was added to or removed from the conversation.
    expect($flow->isServingAgent(6))->toBeTrue()
        ->and($flow->isServingAgent(2))->toBeFalse();
});

it('never pushes audio back into the call — a listening tap only ever spies', function () {
    directoryOfPhones([6 => 'PJSIP/1100', 2 => 'PJSIP/1105']);

    $telephony = fakeTelephony();
    expectLiveCall($telephony);
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1105', 'monitor')->andReturn('monitor-leg');
    $telephony->shouldReceive('join')->once()->with('tap-leg', 'monitor-leg')->andReturn('monitor-conv');

    // 🔴 The whole of "silent" is here. A two-argument snoop leaves the whisper direction
    // at its default of none, so the supervisor's microphone reaches nobody. A three-arg
    // call — slice 3's coaching — would not match this expectation and the test would
    // fail, which is exactly the guard whisper needs when it lands.
    $telephony->shouldReceive('snoop')->once()
        ->withArgs(fn (string $legId, string $spy): bool => $legId === 'agent-leg' && $spy === 'both')
        ->andReturn('tap-leg');

    $flow = liveCallWithAgentSix($telephony);
    $flow->beginListen(agentUserId: 6, supervisorUserId: 2);
    $flow->handle(stasisStart('monitor-leg', ['monitor']));
});

it('releases the tap and folds the supervisors mixer when they hang up, leaving the call alone', function () {
    directoryOfPhones([6 => 'PJSIP/1100', 2 => 'PJSIP/1105']);

    $telephony = fakeTelephony();
    expectLiveCall($telephony);
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1105', 'monitor')->andReturn('monitor-leg');
    $telephony->shouldReceive('snoop')->once()->andReturn('tap-leg');
    $telephony->shouldReceive('join')->once()->with('tap-leg', 'monitor-leg')->andReturn('monitor-conv');

    // All three released, and NOTHING belonging to the call: no stopRecording, no
    // hangup of the caller or the agent, and the call's own conversation is not folded.
    // Mockery's strict matching is what proves the second half.
    $telephony->shouldReceive('hangup')->once()->with('tap-leg');
    $telephony->shouldReceive('hangup')->once()->with('monitor-leg');
    $telephony->shouldReceive('endConversation')->once()->with('monitor-conv');

    $flow = liveCallWithAgentSix($telephony);
    $flow->beginListen(agentUserId: 6, supervisorUserId: 2);
    $flow->handle(stasisStart('monitor-leg', ['monitor']));

    $flow->handle(channelDestroyed('monitor-leg'));

    // The call carries on with the same agent, and a second listen can start cleanly.
    expect($flow->isServingAgent(6))->toBeTrue();
});

it('releases a listening supervisor when the call ends underneath them', function () {
    directoryOfPhones([6 => 'PJSIP/1100', 2 => 'PJSIP/1105']);

    $telephony = fakeTelephony();
    $session = expectLiveCall($telephony);
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1105', 'monitor')->andReturn('monitor-leg');
    $telephony->shouldReceive('snoop')->once()->andReturn('tap-leg');
    $telephony->shouldReceive('join')->once()->with('tap-leg', 'monitor-leg')->andReturn('monitor-conv');

    // 🔴 The monitoring session is released as part of the ordinary teardown. Without
    // this the supervisor is left holding a live phone wired to a tap on a line that no
    // longer exists — silence they can only escape by hanging up — and a tap channel and
    // a bridge are left on the switch for good.
    $telephony->shouldReceive('hangup')->once()->with('tap-leg');
    $telephony->shouldReceive('hangup')->once()->with('monitor-leg');
    $telephony->shouldReceive('endConversation')->once()->with('monitor-conv');
    // …alongside the call's own ordinary ending.
    $telephony->shouldReceive('stopRecording')->once()->with($session);
    $telephony->shouldReceive('hangup')->once()->with('agent-leg');
    $telephony->shouldReceive('endConversation')->once()->with('conv-1');

    $flow = liveCallWithAgentSix($telephony);
    $flow->beginListen(agentUserId: 6, supervisorUserId: 2);
    $flow->handle(stasisStart('monitor-leg', ['monitor']));

    $flow->handle(channelDestroyed('caller-leg'));   // the customer hangs up
});

it('gives up cleanly when the agent leaves while the supervisors phone is still ringing', function () {
    directoryOfPhones([6 => 'PJSIP/1100', 2 => 'PJSIP/1105']);

    $telephony = fakeTelephony();
    $session = expectLiveCall($telephony);
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1105', 'monitor')->andReturn('monitor-leg');

    // The call ends first, which releases the still-ringing monitor leg. No tap is ever
    // created, so none is released, and no mixer is folded.
    $telephony->shouldReceive('stopRecording')->once()->with($session);
    $telephony->shouldReceive('hangup')->once()->with('monitor-leg');
    $telephony->shouldReceive('hangup')->once()->with('caller-leg');
    $telephony->shouldReceive('endConversation')->once()->with('conv-1');
    $telephony->shouldNotReceive('snoop');

    $flow = liveCallWithAgentSix($telephony);
    $flow->beginListen(agentUserId: 6, supervisorUserId: 2);

    $flow->handle(channelDestroyed('agent-leg'));    // the agent hangs up mid-ring
});

it('does not ring a second phone when the same supervisor presses listen twice', function () {
    directoryOfPhones([6 => 'PJSIP/1100', 2 => 'PJSIP/1105']);

    $telephony = fakeTelephony();
    expectLiveCall($telephony);
    // ONCE. A board that refreshes every fifteen seconds invites a double-click, and two
    // legs to the same phone would leave one of them stranded with nothing to hang it up.
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1105', 'monitor')->andReturn('monitor-leg');

    $flow = liveCallWithAgentSix($telephony);
    $flow->beginListen(agentUserId: 6, supervisorUserId: 2);
    $flow->beginListen(agentUserId: 6, supervisorUserId: 2);
});

it('refuses a supervisor who holds no phone of their own', function () {
    directoryOfPhones([6 => 'PJSIP/1100']);   // user 2 was never issued one (PP-12)

    $telephony = fakeTelephony();
    expectLiveCall($telephony);
    // Nothing else is placed. The button is hidden for them, but the signal is reachable
    // from any browser, so the refusal has to live here too.

    $flow = liveCallWithAgentSix($telephony);
    $flow->beginListen(agentUserId: 6, supervisorUserId: 2);
});

it('refuses to listen to an agent who is not on this call', function () {
    directoryOfPhones([6 => 'PJSIP/1100', 2 => 'PJSIP/1105']);

    $telephony = fakeTelephony();
    expectLiveCall($telephony);
    // Agent 99 is not connected here, so no phone is rung — the board's fifteen-second
    // refresh means a click can always name somebody whose call has already moved on.

    $flow = liveCallWithAgentSix($telephony);
    $flow->beginListen(agentUserId: 99, supervisorUserId: 2);
});

it('lets two supervisors listen to the same call at once', function () {
    directoryOfPhones([6 => 'PJSIP/1100', 2 => 'PJSIP/1105', 3 => 'PJSIP/1106']);

    $telephony = fakeTelephony();
    expectLiveCall($telephony);

    // SQ-3: each gets their own phone, their own tap on the agent's line, and their own
    // mixer. Two taps on one line is the arrangement recording already proves works.
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1105', 'monitor')->andReturn('monitor-a');
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1106', 'monitor')->andReturn('monitor-b');
    $telephony->shouldReceive('snoop')->twice()->with('agent-leg', 'both')->andReturn('tap-a', 'tap-b');
    $telephony->shouldReceive('join')->once()->with('tap-a', 'monitor-a')->andReturn('conv-a');
    $telephony->shouldReceive('join')->once()->with('tap-b', 'monitor-b')->andReturn('conv-b');

    // One leaves; the other is completely undisturbed.
    $telephony->shouldReceive('hangup')->once()->with('tap-a');
    $telephony->shouldReceive('hangup')->once()->with('monitor-a');
    $telephony->shouldReceive('endConversation')->once()->with('conv-a');

    $flow = liveCallWithAgentSix($telephony);
    $flow->beginListen(agentUserId: 6, supervisorUserId: 2);
    $flow->handle(stasisStart('monitor-a', ['monitor']));
    $flow->beginListen(agentUserId: 6, supervisorUserId: 3);
    $flow->handle(stasisStart('monitor-b', ['monitor']));

    $flow->handle(channelDestroyed('monitor-a'));
});

it('releases a listening supervisor when a bug tears the call down under them', function () {
    directoryOfPhones([6 => 'PJSIP/1100', 2 => 'PJSIP/1105']);

    $telephony = fakeTelephony();
    expectLiveCall($telephony);
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1105', 'monitor')->andReturn('monitor-leg');
    $telephony->shouldReceive('snoop')->once()->andReturn('tap-leg');
    $telephony->shouldReceive('join')->once()->with('tap-leg', 'monitor-leg')->andReturn('monitor-conv');

    // The switchboard's backstop door (FD-6) and the refused-verb door (abort) both run
    // the same tidy-up, and it is a SEPARATE path from a call ending normally. A monitor
    // orphaned by a crash is the same orphan as one left by a clean ending, so it has to
    // be released here too.
    $telephony->shouldReceive('hangup')->once()->with('tap-leg');
    $telephony->shouldReceive('hangup')->once()->with('monitor-leg');
    $telephony->shouldReceive('endConversation')->once()->with('monitor-conv');
    $telephony->shouldReceive('hangup')->once()->with('caller-leg');
    $telephony->shouldReceive('hangup')->once()->with('agent-leg');

    $flow = liveCallWithAgentSix($telephony);
    $flow->beginListen(agentUserId: 6, supervisorUserId: 2);
    $flow->handle(stasisStart('monitor-leg', ['monitor']));

    $flow->discard();
});

it('will not tap an agent whose own phone is still ringing', function () {
    directoryOfPhones([6 => 'PJSIP/1100', 7 => 'PJSIP/1101', 2 => 'PJSIP/1105']);

    // A conference rings a SECOND agent in. Their board row already reads "On a call"
    // the moment they are booked, so the floor board legitimately offers a Listen button
    // on a row whose phone has not been picked up yet.
    app()->instance(AgentRouter::class, new class extends AgentRouter
    {
        public function reserveFreeAgent(int $tenantId, array $skipUserIds = []): ?int
        {
            return $skipUserIds === [] && ! isset($this->rung) ? ($this->rung = 6) : 7;
        }

        public function releaseReservation(int $tenantId, int $userId): void {}
    });

    $telephony = fakeTelephony();
    expectLiveCall($telephony);
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1101', 'agent')->andReturn('added-leg');

    // 🔴 Nothing is rung and nothing is tapped. A tap on a line nobody has picked up
    // carries no conversation, and the supervisor would sit in silence wondering whether
    // the feature works. They can listen the moment that agent actually answers.
    $telephony->shouldNotReceive('snoop');

    $flow = liveCallWithAgentSix($telephony);
    $flow->beginConference(3);                       // agent 7's phone starts ringing
    $flow->beginListen(agentUserId: 7, supervisorUserId: 2);
});
