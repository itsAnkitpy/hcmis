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
    $telephony->shouldReceive('snoop')->once()->with('agent-leg', 'both', 'none')->andReturn('tap-leg');
    // A mixer of their own — never the call's conversation.
    $telephony->shouldReceive('join')->once()->with('tap-leg', 'monitor-leg')->andReturn('monitor-conv');

    $flow = liveCallWithAgentSix($telephony);
    $flow->beginMonitor(agentUserId: 6, supervisorUserId: 2);
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

    // 🔴 The whole of "silent" is here, and slice 3 made it say so out loud. The tap is
    // asked for with NO whisper direction, so the supervisor's microphone reaches nobody
    // however loudly they cough. Asking for 'out' here — which is what the Whisper button
    // does — would not match, and this test is what stands between the two buttons.
    //
    // Nothing reaches the wire either way: the provider leaves 'none' OFF the request,
    // so a listening tap is byte-identical to the one recording has made on every call
    // this system has ever carried (AsteriskAriProviderTest pins that separately).
    $telephony->shouldReceive('snoop')->once()
        ->withArgs(fn (string $legId, string $spy, string $whisper): bool => $legId === 'agent-leg'
            && $spy === 'both'
            && $whisper === 'none')
        ->andReturn('tap-leg');

    $flow = liveCallWithAgentSix($telephony);
    $flow->beginMonitor(agentUserId: 6, supervisorUserId: 2);
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
    $flow->beginMonitor(agentUserId: 6, supervisorUserId: 2);
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
    $flow->beginMonitor(agentUserId: 6, supervisorUserId: 2);
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
    $flow->beginMonitor(agentUserId: 6, supervisorUserId: 2);

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
    $flow->beginMonitor(agentUserId: 6, supervisorUserId: 2);
    $flow->beginMonitor(agentUserId: 6, supervisorUserId: 2);
});

it('refuses a supervisor who holds no phone of their own', function () {
    directoryOfPhones([6 => 'PJSIP/1100']);   // user 2 was never issued one (PP-12)

    $telephony = fakeTelephony();
    expectLiveCall($telephony);
    // Nothing else is placed. The button is hidden for them, but the signal is reachable
    // from any browser, so the refusal has to live here too.

    $flow = liveCallWithAgentSix($telephony);
    $flow->beginMonitor(agentUserId: 6, supervisorUserId: 2);
});

it('refuses to listen to an agent who is not on this call', function () {
    directoryOfPhones([6 => 'PJSIP/1100', 2 => 'PJSIP/1105']);

    $telephony = fakeTelephony();
    expectLiveCall($telephony);
    // Agent 99 is not connected here, so no phone is rung — the board's fifteen-second
    // refresh means a click can always name somebody whose call has already moved on.

    $flow = liveCallWithAgentSix($telephony);
    $flow->beginMonitor(agentUserId: 99, supervisorUserId: 2);
});

it('lets two supervisors listen to the same call at once', function () {
    directoryOfPhones([6 => 'PJSIP/1100', 2 => 'PJSIP/1105', 3 => 'PJSIP/1106']);

    $telephony = fakeTelephony();
    expectLiveCall($telephony);

    // SQ-3: each gets their own phone, their own tap on the agent's line, and their own
    // mixer. Two taps on one line is the arrangement recording already proves works.
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1105', 'monitor')->andReturn('monitor-a');
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1106', 'monitor')->andReturn('monitor-b');
    $telephony->shouldReceive('snoop')->twice()->with('agent-leg', 'both', 'none')->andReturn('tap-a', 'tap-b');
    $telephony->shouldReceive('join')->once()->with('tap-a', 'monitor-a')->andReturn('conv-a');
    $telephony->shouldReceive('join')->once()->with('tap-b', 'monitor-b')->andReturn('conv-b');

    // One leaves; the other is completely undisturbed.
    $telephony->shouldReceive('hangup')->once()->with('tap-a');
    $telephony->shouldReceive('hangup')->once()->with('monitor-a');
    $telephony->shouldReceive('endConversation')->once()->with('conv-a');

    $flow = liveCallWithAgentSix($telephony);
    $flow->beginMonitor(agentUserId: 6, supervisorUserId: 2);
    $flow->handle(stasisStart('monitor-a', ['monitor']));
    $flow->beginMonitor(agentUserId: 6, supervisorUserId: 3);
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
    $flow->beginMonitor(agentUserId: 6, supervisorUserId: 2);
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
    $flow->beginMonitor(agentUserId: 7, supervisorUserId: 2);
});

/*
|--------------------------------------------------------------------------
| SM slice 3 — whisper (coaching)
|--------------------------------------------------------------------------
|
| The same tap as listening, with one word changed. Everything else in this file
| already covers coaching too, because both buttons walk the identical path: the
| refusals, the double-click guard, the three teardown doors and the two-supervisors
| case are all mode-blind by construction.
|
| So these cases only assert what the mode actually changes — which way the audio is
| pointed — plus the one gap coaching turns from cosmetic into misleading: a tap whose
| agent line goes away underneath it.
*/

it('points the supervisors voice into the AGENT ear and nowhere else when coaching', function () {
    directoryOfPhones([6 => 'PJSIP/1100', 2 => 'PJSIP/1105']);

    $telephony = fakeTelephony();
    expectLiveCall($telephony);
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1105', 'monitor')->andReturn('monitor-leg');
    $telephony->shouldReceive('join')->once()->with('tap-leg', 'monitor-leg')->andReturn('monitor-conv');

    // 🔴 THE WHOLE OF SLICE 3 IS THE WORD 'out'. It is the audio written TO the agent's
    // line — what the agent hears — so the supervisor lands in the agent's ear alone.
    // 'in' would be the audio read FROM that line, which is what carries on to the
    // customer, and is the one outcome this feature must never produce. Asterisk 20 runs
    // the spy and whisper directions through the same translation, which is why
    // recording's own two taps use these same two words for said-versus-heard.
    $telephony->shouldReceive('snoop')->once()
        ->withArgs(fn (string $legId, string $spy, string $whisper): bool => $legId === 'agent-leg'
            && $spy === 'both'
            && $whisper === 'out')
        ->andReturn('tap-leg');

    $flow = liveCallWithAgentSix($telephony);
    $flow->beginMonitor(agentUserId: 6, supervisorUserId: 2, mode: 'whisper');
    $flow->handle(stasisStart('monitor-leg', ['monitor']));

    // Still not a participant. Coaching moves the audio, never the call.
    expect($flow->isServingAgent(6))->toBeTrue()
        ->and($flow->isServingAgent(2))->toBeFalse();
});

it('taps silently for any mode it does not recognise, so a bad mode can only under-share', function () {
    directoryOfPhones([6 => 'PJSIP/1100', 2 => 'PJSIP/1105']);

    $telephony = fakeTelephony();
    expectLiveCall($telephony);
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1105', 'monitor')->andReturn('monitor-leg');
    $telephony->shouldReceive('join')->once()->andReturn('monitor-conv');

    // Nothing downstream of the two buttons keeps a list of allowed modes, because it
    // does not need one: only the exact word 'whisper' opens a microphone, so anything
    // else — a typo, a stale signal, a crafted one — is a silent tap, which is the safe
    // direction to fail in.
    $telephony->shouldReceive('snoop')->once()->with('agent-leg', 'both', 'none')->andReturn('tap-leg');

    $flow = liveCallWithAgentSix($telephony);
    $flow->beginMonitor(agentUserId: 6, supervisorUserId: 2, mode: 'BARGE');
    $flow->handle(stasisStart('monitor-leg', ['monitor']));
});

it('ends a supervisors session when the agent line it was tapping goes away under them', function () {
    directoryOfPhones([6 => 'PJSIP/1100', 2 => 'PJSIP/1105']);

    $telephony = fakeTelephony();
    expectLiveCall($telephony);
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1105', 'monitor')->andReturn('monitor-leg');
    $telephony->shouldReceive('snoop')->once()->andReturn('tap-leg');
    $telephony->shouldReceive('join')->once()->with('tap-leg', 'monitor-leg')->andReturn('monitor-conv');

    // 🔴 The supervisor's phone is put down and their mixer folded. Asterisk has already
    // ended the tap itself, so nothing hangs it up a second time — and NOTHING belonging
    // to the call is touched, which Mockery's strict matching is what proves.
    $telephony->shouldReceive('hangup')->once()->with('monitor-leg');
    $telephony->shouldReceive('endConversation')->once()->with('monitor-conv');

    $switchboard = new Switchboard($telephony);
    $flow = new CallToAgentFlow($telephony, $switchboard);
    $flow->handle(stasisStart('caller-leg', []));
    $flow->handle(stasisStart('agent-leg', ['agent']));
    $flow->beginMonitor(agentUserId: 6, supervisorUserId: 2, mode: 'whisper');
    $flow->handle(stasisStart('monitor-leg', ['monitor']));

    // 🔴 Fed to the SWITCHBOARD, not to the handler, because the half being proved here
    // is that the tap was put in the phone-book at all. Recording's own taps are not, and
    // are silently dropped at this line — so a tap that was never registered would make
    // this test pass for the wrong reason if the event were handed straight over.
    $switchboard->handle(channelDestroyed('tap-leg'));

    // The call is untouched and the supervisor can start again without a page refresh.
    expect($flow->isServingAgent(6))->toBeTrue();
});

it('lets a real transfer end the coaching session rather than leaving the coach talking to nobody', function () {
    directoryOfPhones([6 => 'PJSIP/1100', 7 => 'PJSIP/1101', 2 => 'PJSIP/1105']);

    // Agent 6 takes the call; the transfer then books agent 7, the same first-then-second
    // shape the conference case above uses.
    app()->instance(AgentRouter::class, new class extends AgentRouter
    {
        public function reserveFreeAgent(int $tenantId, array $skipUserIds = []): ?int
        {
            return isset($this->rung) ? 7 : ($this->rung = 6);
        }

        public function releaseReservation(int $tenantId, int $userId): void {}
    });

    $telephony = fakeTelephony();
    expectLiveCall($telephony);
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1105', 'monitor')->andReturn('monitor-leg');
    $telephony->shouldReceive('snoop')->once()->andReturn('tap-leg');
    $telephony->shouldReceive('join')->once()->with('tap-leg', 'monitor-leg')->andReturn('monitor-conv');

    // The transfer itself: ring agent 7, slip them into the live conversation, take
    // agent 6 out and hang their line up.
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1101', 'agent')->andReturn('transfer-leg');
    $telephony->shouldReceive('addToBridge')->once()->with('conv-1', 'transfer-leg');
    $telephony->shouldReceive('removeFromBridge')->once()->with('conv-1', 'agent-leg');
    $telephony->shouldReceive('hangup')->once()->with('agent-leg');

    // 🔴 And with it, the coach. Agent 6's line is the one the tap sits on, so Asterisk
    // ends the tap and the supervisor's half of this is over. Without it they carry on
    // talking into a line that stopped existing — which while listening merely sounds
    // broken, but while coaching reads exactly like an agent ignoring them.
    $telephony->shouldReceive('hangup')->once()->with('monitor-leg');
    $telephony->shouldReceive('endConversation')->once()->with('monitor-conv');

    $switchboard = new Switchboard($telephony);
    $flow = new CallToAgentFlow($telephony, $switchboard);
    $flow->handle(stasisStart('caller-leg', []));
    $flow->handle(stasisStart('agent-leg', ['agent']));
    $flow->beginMonitor(agentUserId: 6, supervisorUserId: 2, mode: 'whisper');
    $flow->handle(stasisStart('monitor-leg', ['monitor']));

    $flow->beginTransfer(3);
    $flow->handle(stasisStart('transfer-leg', ['agent']));   // agent 7 picks up, agent 6 is dropped
    $switchboard->handle(channelDestroyed('agent-leg'));
    $switchboard->handle(channelDestroyed('tap-leg'));       // Asterisk ends the tap with its line

    expect($flow->isServingAgent(7))->toBeTrue()
        ->and($flow->isServingAgent(6))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| SM slice 4 — barge
|--------------------------------------------------------------------------
|
| The one mode the customer can hear, and the one that is not a tap at all: the
| supervisor's own line joins the call's conversation, the same verb a conferenced
| third agent already uses.
|
| Everything mode-blind in this file covers barge too — the refusals, the double-click
| guard, the two-supervisors case. So these cases assert only what barge changes: that
| it joins instead of tapping, that leaving does not take the call with it, and that a
| supervisor who is audible is still not a participant (SM-5).
*/

it('puts a barging supervisor into the calls own conversation instead of tapping anything', function () {
    directoryOfPhones([6 => 'PJSIP/1100', 2 => 'PJSIP/1105']);

    $telephony = fakeTelephony();
    expectLiveCall($telephony);
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1105', 'monitor')->andReturn('monitor-leg');

    // 🔴 The whole of slice 4. Into conv-1 — the CALL's own bridge, holding the customer
    // and the agent — which is why all three hear each other with no second audio path
    // to point the wrong way.
    $telephony->shouldReceive('addToBridge')->once()->with('conv-1', 'monitor-leg');

    // No tap and no mixer of their own. Both would be wrong rather than merely wasteful:
    // a tap would put the supervisor's voice on a second path as well, and a mixer of
    // their own is the thing teardown ends — which for a barge would be the call's.
    $telephony->shouldNotReceive('snoop');
    $telephony->shouldNotReceive('join');

    $flow = liveCallWithAgentSix($telephony);
    $flow->beginMonitor(agentUserId: 6, supervisorUserId: 2, mode: 'barge');
    $flow->handle(stasisStart('monitor-leg', ['monitor']));
});

it('leaves the call running when a barging supervisor hangs up, and never ends the calls own bridge', function () {
    directoryOfPhones([6 => 'PJSIP/1100', 2 => 'PJSIP/1105']);

    $telephony = fakeTelephony();
    expectLiveCall($telephony);
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1105', 'monitor')->andReturn('monitor-leg');
    $telephony->shouldReceive('addToBridge')->once()->with('conv-1', 'monitor-leg');

    // Stopping a barge is one verb: hang the supervisor's own line up. Asterisk takes a
    // destroyed channel out of its bridge without being asked.
    $telephony->shouldReceive('hangup')->once()->with('monitor-leg');

    // 🔴 THE TWO THINGS THAT WOULD END A CUSTOMER'S CALL. Mockery fails on either, and
    // they are named rather than left to the strict mock so a reader sees what is being
    // guarded: the call's bridge must survive, and so must both the legs in it.
    $telephony->shouldNotReceive('endConversation')->with('conv-1');
    $telephony->shouldNotReceive('hangup')->with('caller-leg');
    $telephony->shouldNotReceive('hangup')->with('agent-leg');

    $flow = liveCallWithAgentSix($telephony);
    $flow->beginMonitor(agentUserId: 6, supervisorUserId: 2, mode: 'barge');
    $flow->handle(stasisStart('monitor-leg', ['monitor']));

    // The supervisor presses Leave the call, or simply closes the tab — both are a
    // hang-up and both arrive here.
    $flow->handle(channelDestroyed('monitor-leg'));

    expect($flow->isServingAgent(6))->toBeTrue();
});

it('never makes a barging supervisor a participant, so no per-agent figure can move', function () {
    directoryOfPhones([6 => 'PJSIP/1100', 2 => 'PJSIP/1105']);

    $telephony = fakeTelephony();
    expectLiveCall($telephony);
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1105', 'monitor')->andReturn('monitor-leg');
    $telephony->shouldReceive('addToBridge')->once()->with('conv-1', 'monitor-leg');
    $telephony->shouldReceive('hangup')->once()->with('monitor-leg');

    $flow = liveCallWithAgentSix($telephony);

    // 🔴 SM-5, THE HALF SLICE 2 COULD NOT PROVE. Every per-agent number in this system —
    // the productivity report, agent detail, the abandoned rate — is built by counting
    // rows filed against a participant, and participation is exactly this membership
    // test. A supervisor who reached it would land in all of them at once as an extra
    // agent on somebody else's call.
    //
    // Checked before, during and after, because barge is the one mode that puts their
    // line in the same bridge as the agent's — which is precisely what would make a
    // careless implementation add them to the participant set.
    expect($flow->isServingAgent(2))->toBeFalse();

    $flow->beginMonitor(agentUserId: 6, supervisorUserId: 2, mode: 'barge');
    $flow->handle(stasisStart('monitor-leg', ['monitor']));

    expect($flow->isServingAgent(2))->toBeFalse()
        ->and($flow->isServingAgent(6))->toBeTrue();

    $flow->handle(channelDestroyed('monitor-leg'));

    expect($flow->isServingAgent(2))->toBeFalse()
        ->and($flow->isServingAgent(6))->toBeTrue();
});
