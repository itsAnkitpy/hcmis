<?php

use App\Telephony\AgentRouter;
use App\Telephony\Flows\CallToAgentFlow;
use App\Telephony\Flows\Switchboard;
use App\Telephony\RecordingSession;
use App\Telephony\TelephonyProvider;
use Illuminate\Support\Facades\Queue;

/**
 * B2.4b-i 3-way conference — the handler mechanics, event-driven, against a mocked
 * provider (no Asterisk, no network). A is on a live inbound call; a conference rings
 * a free agent B while the caller stays with A (the never-strand rule, shared with cold
 * transfer). These prove the conference fork (add B, KEEP A — the only difference from
 * a transfer is the absence of remove + hang-up of A, CD-4), the participant-set
 * teardown (CD-2: any party leaving handled uniformly), the no-answer release, the
 * mid-ring edges, and the 3-way cap (CD-5). The switchboard's find-the-right-handler
 * routing lives in SwitchboardTest; here we drive one handler via beginConference().
 */
beforeEach(function () {
    config()->set('telephony.agent.endpoint', 'PJSIP/1003');
    config()->set('telephony.agent.directory', []);   // the single-endpoint fallback rings 'PJSIP/1003'
    Queue::fake();
});

/**
 * A router that hands back a SEQUENCE of agent ids (A then B then …), recording every
 * reserve/release — so a conference can reserve a DISTINCT second agent (B) and the
 * "either agent reaches the call" set-membership check is meaningful.
 */
function sequenceAgentRouter(array $ids): AgentRouter
{
    $router = new class($ids) extends AgentRouter
    {
        /** @var array<int, int> */
        public array $reserved = [];

        /** @var array<int, array{int, int}> */
        public array $released = [];

        private int $cursor = 0;

        /** @param array<int, int> $ids */
        public function __construct(private readonly array $ids) {}

        public function reserveFreeAgent(int $tenantId): ?int
        {
            $this->reserved[] = $tenantId;

            return $this->ids[$this->cursor++] ?? null;
        }

        public function releaseReservation(int $tenantId, int $userId): void
        {
            $this->released[] = [$tenantId, $userId];
        }
    };

    app()->instance(AgentRouter::class, $router);

    return $router;
}

/**
 * Drive a handler to a live inbound call (caller + A connected, recording), returning
 * [$flow, $switchboard]. Shared setup for the conference cases that begin from a 3-way
 * starting point. The provider mock must already expect answer/placeCall(A)/join/record.
 *
 * @return array{0: CallToAgentFlow, 1: Switchboard}
 */
function inboundCallWithAgentA(TelephonyProvider $telephony): array
{
    $switchboard = new Switchboard($telephony);
    $flow = new CallToAgentFlow($telephony, $switchboard);

    $flow->handle(stasisStart('caller-leg', []));          // caller dials in
    $flow->handle(stasisStart('agent-leg', ['agent']));    // A answers -> InCall

    return [$flow, $switchboard];
}

it('on B answering a conference: adds B and KEEPS A — the 3-way — recording untouched', function () {
    sequenceAgentRouter([6, 7]);   // A = 6, B = 7
    $session = new RecordingSession('caller-leg', 'call-1', 'snoop-said', 'snoop-heard');

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-leg');
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent', null)->andReturn('agent-leg');
    $telephony->shouldReceive('join')->once()->with('caller-leg', 'agent-leg')->andReturn('conv-1');
    $telephony->shouldReceive('startRecording')->once()->with('caller-leg', Mockery::type('string'))->andReturn($session);
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent')->andReturn('conf-leg');
    // The conference fork: add B and KEEP A — NO removeFromBridge, NO hang-up of A.
    $telephony->shouldReceive('addToBridge')->once()->with('conv-1', 'conf-leg');
    $telephony->shouldNotReceive('removeFromBridge');
    $telephony->shouldNotReceive('hangup');

    [$flow] = inboundCallWithAgentA($telephony);
    $flow->beginConference(3);
    $flow->handle(stasisStart('conf-leg', ['agent']));   // B answers -> completeConference

    // startRecording fired exactly once -> the recording was never restarted (the snoop
    // stays on the caller leg). Both agents now serve the call (set membership), so the
    // find-handler scan reaches it by EITHER agent.
    expect($flow->isServingAgent(6))->toBeTrue()
        ->and($flow->isServingAgent(7))->toBeTrue()
        ->and($flow->isServingAgent(99))->toBeFalse();
});

it('when A leaves a 3-way conference: the caller and B keep talking, then end cleanly with B (transfer-via-conference)', function () {
    sequenceAgentRouter([6, 7]);
    $session = new RecordingSession('caller-leg', 'call-1', 'snoop-said', 'snoop-heard');

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-leg');
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent', null)->andReturn('agent-leg');
    $telephony->shouldReceive('join')->once()->andReturn('conv-1');
    $telephony->shouldReceive('startRecording')->once()->andReturn($session);
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent')->andReturn('conf-leg');
    $telephony->shouldReceive('addToBridge')->once()->with('conv-1', 'conf-leg');
    // A drops out: NOTHING happens on the wire (A's leg is already gone, B + caller stay).
    // Later the caller hangs up: end with B as the survivor, one continuous recording.
    $telephony->shouldReceive('stopRecording')->once()->with($session);
    $telephony->shouldReceive('hangup')->once()->with('conf-leg');
    $telephony->shouldReceive('endConversation')->once()->with('conv-1');

    [$flow] = inboundCallWithAgentA($telephony);
    $flow->beginConference(3);
    $flow->handle(stasisStart('conf-leg', ['agent']));   // 3-way: caller + 6 + 7

    $flow->handle(channelDestroyed('agent-leg'));         // A leaves the 3-way
    expect($flow->isServingAgent(7))->toBeTrue()          // B carries on with the caller
        ->and($flow->isServingAgent(6))->toBeFalse();

    $flow->handle(channelDestroyed('caller-leg'));        // the caller hangs up -> end with B
});

it('when B leaves a 3-way conference: the caller and A keep talking, then end cleanly with A', function () {
    sequenceAgentRouter([6, 7]);
    $session = new RecordingSession('caller-leg', 'call-1', 'snoop-said', 'snoop-heard');

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-leg');
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent', null)->andReturn('agent-leg');
    $telephony->shouldReceive('join')->once()->andReturn('conv-1');
    $telephony->shouldReceive('startRecording')->once()->andReturn($session);
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent')->andReturn('conf-leg');
    $telephony->shouldReceive('addToBridge')->once()->with('conv-1', 'conf-leg');
    $telephony->shouldReceive('stopRecording')->once()->with($session);
    $telephony->shouldReceive('hangup')->once()->with('agent-leg');   // A is the survivor
    $telephony->shouldReceive('endConversation')->once()->with('conv-1');

    [$flow] = inboundCallWithAgentA($telephony);
    $flow->beginConference(3);
    $flow->handle(stasisStart('conf-leg', ['agent']));   // 3-way

    $flow->handle(channelDestroyed('conf-leg'));          // B leaves the 3-way
    expect($flow->isServingAgent(6))->toBeTrue()
        ->and($flow->isServingAgent(7))->toBeFalse();

    $flow->handle(channelDestroyed('caller-leg'));        // end with A
});

it('when the caller hangs up a 3-way conference: both agents are dropped', function () {
    sequenceAgentRouter([6, 7]);
    $session = new RecordingSession('caller-leg', 'call-1', 'snoop-said', 'snoop-heard');

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-leg');
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent', null)->andReturn('agent-leg');
    $telephony->shouldReceive('join')->once()->andReturn('conv-1');
    $telephony->shouldReceive('startRecording')->once()->andReturn($session);
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent')->andReturn('conf-leg');
    $telephony->shouldReceive('addToBridge')->once()->with('conv-1', 'conf-leg');
    // The caller leaves -> end all: BOTH agents hung up, recording stopped, conversation folded.
    $telephony->shouldReceive('stopRecording')->once()->with($session);
    $telephony->shouldReceive('hangup')->once()->with('agent-leg');
    $telephony->shouldReceive('hangup')->once()->with('conf-leg');
    $telephony->shouldReceive('endConversation')->once()->with('conv-1');

    [$flow] = inboundCallWithAgentA($telephony);
    $flow->beginConference(3);
    $flow->handle(stasisStart('conf-leg', ['agent']));   // 3-way
    $flow->handle(channelDestroyed('caller-leg'));        // the caller hangs up
});

it('ends the call when the last agent leaves a conference one by one (only-one-left -> end)', function () {
    sequenceAgentRouter([6, 7]);
    $session = new RecordingSession('caller-leg', 'call-1', 'snoop-said', 'snoop-heard');

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-leg');
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent', null)->andReturn('agent-leg');
    $telephony->shouldReceive('join')->once()->andReturn('conv-1');
    $telephony->shouldReceive('startRecording')->once()->andReturn($session);
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent')->andReturn('conf-leg');
    $telephony->shouldReceive('addToBridge')->once()->with('conv-1', 'conf-leg');
    // A leaves -> caller + B continue (no wire activity). B then leaves -> caller alone -> end.
    $telephony->shouldReceive('stopRecording')->once()->with($session);
    $telephony->shouldReceive('hangup')->once()->with('caller-leg');   // the lone caller is dropped
    $telephony->shouldReceive('endConversation')->once()->with('conv-1');

    [$flow] = inboundCallWithAgentA($telephony);
    $flow->beginConference(3);
    $flow->handle(stasisStart('conf-leg', ['agent']));   // 3-way

    $flow->handle(channelDestroyed('agent-leg'));         // A leaves; caller + B continue
    $flow->handle(channelDestroyed('conf-leg'));          // B leaves; caller alone -> end
});

it('on conference B no-answer: releases B and leaves the caller with A (Fold B)', function () {
    $router = sequenceAgentRouter([6, 7]);
    $session = new RecordingSession('caller-leg', 'call-1', 'snoop-said', 'snoop-heard');

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-leg');
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent', null)->andReturn('agent-leg');
    $telephony->shouldReceive('join')->once()->andReturn('conv-1');
    $telephony->shouldReceive('startRecording')->once()->andReturn($session);
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent')->andReturn('conf-leg');
    // B never answered -> no surgery. The call later ends normally with A as the agent.
    $telephony->shouldNotReceive('addToBridge');
    $telephony->shouldReceive('stopRecording')->once()->with($session);
    $telephony->shouldReceive('hangup')->once()->with('agent-leg');   // A is still the survivor
    $telephony->shouldReceive('endConversation')->once()->with('conv-1');

    [$flow] = inboundCallWithAgentA($telephony);
    $flow->beginConference(3);
    $flow->handle(channelDestroyed('conf-leg'));     // B rings out / declines
    expect($flow->isServingAgent(6))->toBeTrue();    // A is still on the call
    $flow->handle(channelDestroyed('caller-leg'));   // the caller hangs up later

    // B's reservation (the SECOND reserved id, 7) was released; A's connected tag was not.
    expect($router->released)->toBe([[3, 7]]);
});

it('when nobody is free at conference time: touches nothing — the caller stays with A', function () {
    $router = sequenceAgentRouter([6]);   // A = 6; the conference reserve finds nobody (no 2nd id)
    $session = new RecordingSession('caller-leg', 'call-1', 'snoop-said', 'snoop-heard');

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-leg');
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent', null)->andReturn('agent-leg');
    $telephony->shouldReceive('join')->once()->andReturn('conv-1');
    $telephony->shouldReceive('startRecording')->once()->andReturn($session);
    // No second placeCall — nobody to ring. The call ends normally with A.
    $telephony->shouldReceive('stopRecording')->once()->with($session);
    $telephony->shouldReceive('hangup')->once()->with('agent-leg');
    $telephony->shouldReceive('endConversation')->once()->with('conv-1');

    [$flow] = inboundCallWithAgentA($telephony);
    $flow->beginConference(3);                        // nobody free -> no-op
    $flow->handle(channelDestroyed('caller-leg'));    // A still has the caller

    expect($router->reserved)->toBe([3, 3]);          // A's reserve + the failed B reserve
    expect($router->released)->toBe([]);              // nothing to release — B was never reserved
});

it('when the caller hangs up mid-conference-ring: ends the call cleanly and releases B', function () {
    $router = sequenceAgentRouter([6, 7]);
    $session = new RecordingSession('caller-leg', 'call-1', 'snoop-said', 'snoop-heard');

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-leg');
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent', null)->andReturn('agent-leg');
    $telephony->shouldReceive('join')->once()->andReturn('conv-1');
    $telephony->shouldReceive('startRecording')->once()->andReturn($session);
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent')->andReturn('conf-leg');
    // The caller leaves mid-ring: the still-ringing B is hung up, recording stopped, A
    // (the survivor) hung up, the conversation folded. The caller is never left alone.
    $telephony->shouldReceive('hangup')->once()->with('conf-leg');
    $telephony->shouldReceive('stopRecording')->once()->with($session);
    $telephony->shouldReceive('hangup')->once()->with('agent-leg');
    $telephony->shouldReceive('endConversation')->once()->with('conv-1');

    [$flow] = inboundCallWithAgentA($telephony);
    $flow->beginConference(3);
    $flow->handle(channelDestroyed('caller-leg'));   // the customer abandons mid-ring

    expect($router->released)->toBe([[3, 7]]);
});

it('when A hangs up mid-conference-ring: ends the call cleanly rather than stranding the caller, and releases B', function () {
    $router = sequenceAgentRouter([6, 7]);
    $session = new RecordingSession('caller-leg', 'call-1', 'snoop-said', 'snoop-heard');

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-leg');
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent', null)->andReturn('agent-leg');
    $telephony->shouldReceive('join')->once()->andReturn('conv-1');
    $telephony->shouldReceive('startRecording')->once()->andReturn($session);
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent')->andReturn('conf-leg');
    $telephony->shouldReceive('hangup')->once()->with('conf-leg');     // B (still ringing)
    $telephony->shouldReceive('stopRecording')->once()->with($session);
    $telephony->shouldReceive('hangup')->once()->with('caller-leg');   // the caller is the survivor
    $telephony->shouldReceive('endConversation')->once()->with('conv-1');

    [$flow] = inboundCallWithAgentA($telephony);
    $flow->beginConference(3);
    $flow->handle(channelDestroyed('agent-leg'));   // A drops mid-ring (shouldn't, but)

    expect($router->released)->toBe([[3, 7]]);
});

it('caps the conference at 3-way: a second conference on a 3-party call is a no-op', function () {
    $router = sequenceAgentRouter([6, 7, 8]);   // A, B, then a would-be C
    $session = new RecordingSession('caller-leg', 'call-1', 'snoop-said', 'snoop-heard');

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-leg');
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent', null)->andReturn('agent-leg');
    $telephony->shouldReceive('join')->once()->andReturn('conv-1');
    $telephony->shouldReceive('startRecording')->once()->andReturn($session);
    // Exactly ONE added-agent ring (B). The second conference is capped BEFORE it reserves
    // or rings — so no third placeCall, and no third reserve.
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent')->andReturn('conf-leg');
    $telephony->shouldReceive('addToBridge')->once()->with('conv-1', 'conf-leg');

    [$flow] = inboundCallWithAgentA($telephony);
    $flow->beginConference(3);
    $flow->handle(stasisStart('conf-leg', ['agent']));   // 3-way reached (caller + 6 + 7)

    $flow->beginConference(3);                            // a 4th party would exceed the cap -> no-op

    expect($router->reserved)->toBe([3, 3]);             // A's inbound reserve + B's; the 3rd was capped
});
