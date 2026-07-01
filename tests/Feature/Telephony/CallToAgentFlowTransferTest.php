<?php

use App\Jobs\MergeCallRecordingJob;
use App\Telephony\AgentRouter;
use App\Telephony\Flows\CallToAgentFlow;
use App\Telephony\Flows\Switchboard;
use App\Telephony\RecordingSession;
use App\Telephony\TelephonyProvider;
use Illuminate\Support\Facades\Queue;

/**
 * B2.4a cold transfer — the handler mechanics, event-driven, against a mocked
 * provider (no Asterisk, no network). A is on a live inbound call; a transfer
 * rings a free agent B while the caller stays with A (the "supervised cold
 * transfer", TD-5). These prove the surgery (add B / remove A / promote B), the
 * recording riding through unbroken (TD-3/TD-6 — the snoop never moves), and the
 * three teardown edges (B no-answer, caller hangs up mid-ring, A hangs up
 * mid-ring). The switchboard's find-the-right-handler routing lives in
 * SwitchboardTest; here we drive one handler directly via beginTransfer().
 */
beforeEach(function () {
    config()->set('telephony.agent.endpoint', 'PJSIP/1003');
    config()->set('telephony.agent.directory', []);   // the single-endpoint fallback rings 'PJSIP/1003'
    fakeAgentRouter();                                  // reserves agent 6 for both A and B by default
    Queue::fake();
});

it('rings a free agent (B) while keeping the caller with the current agent (A) when a transfer begins', function () {
    $router = fakeAgentRouter(6);
    $session = new RecordingSession('caller-leg', 'call-1', 'snoop-said', 'snoop-heard');

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-leg');
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent', null)->andReturn('agent-leg');
    $telephony->shouldReceive('join')->once()->with('caller-leg', 'agent-leg')->andReturn('conv-1');
    $telephony->shouldReceive('startRecording')->once()->with('caller-leg', Mockery::type('string'))->andReturn($session);
    // The transfer rings B (a second agent leg, no caller-ID — the customer number is
    // not retained past the first ring in B2.4a). A and the caller are untouched.
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent')->andReturn('transfer-leg');

    $switchboard = new Switchboard($telephony);
    $flow = new CallToAgentFlow($telephony, $switchboard);

    $flow->handle(stasisStart('caller-leg', []));
    $flow->handle(stasisStart('agent-leg', ['agent']));   // A answers -> InCall, serving agent = 6
    $flow->beginTransfer(3);

    // B is reserved in the user-event's tenant (TD-6): A's connect reserve, then B's.
    expect($router->reserved)->toBe([3, 3]);
    Queue::assertNothingPushed();
});

it('on B answering: adds B, removes + hangs up A, and the recording rides through unbroken', function () {
    fakeAgentRouter(6);
    $session = new RecordingSession('caller-leg', 'call-1', 'snoop-said', 'snoop-heard');

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-leg');
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent', null)->andReturn('agent-leg');
    $telephony->shouldReceive('join')->once()->with('caller-leg', 'agent-leg')->andReturn('conv-1');
    $telephony->shouldReceive('startRecording')->once()->with('caller-leg', Mockery::type('string'))->andReturn($session);
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent')->andReturn('transfer-leg');
    // The surgery: add B FIRST (no gap for the caller), then remove + hang up A.
    $telephony->shouldReceive('addToBridge')->once()->with('conv-1', 'transfer-leg');
    $telephony->shouldReceive('removeFromBridge')->once()->with('conv-1', 'agent-leg');
    $telephony->shouldReceive('hangup')->once()->with('agent-leg');
    // The call ends later (customer hangs up): the SAME recording session is stopped
    // (never restarted across the hand-off), B is the survivor, the conversation closed.
    $telephony->shouldReceive('stopRecording')->once()->with($session);
    $telephony->shouldReceive('hangup')->once()->with('transfer-leg');
    $telephony->shouldReceive('endConversation')->once()->with('conv-1');

    $switchboard = new Switchboard($telephony);
    $flow = new CallToAgentFlow($telephony, $switchboard);

    $flow->handle(stasisStart('caller-leg', []));
    $ticket = $flow->ticketNumber();                         // capture before teardown's reset() clears it
    $flow->handle(stasisStart('agent-leg', ['agent']));
    $flow->beginTransfer(3);
    $flow->handle(stasisStart('transfer-leg', ['agent']));   // B answers -> completeTransfer
    $flow->handle(channelDestroyed('caller-leg'));            // the customer hangs up later

    // startRecording fired exactly once -> the recording was never restarted across the
    // transfer (the snoop stays on the caller leg). The merge keyed by the call's TICKET
    // (TH-4 — the inbound recording id is now the ticket) confirms the single continuous
    // file spans A then B.
    $switchboard->handle(recordingFinished('call-1-said'));
    $switchboard->handle(recordingFinished('call-1-heard'));

    Queue::assertPushed(
        MergeCallRecordingJob::class,
        fn (MergeCallRecordingJob $job): bool => $job->callId === $ticket && $job->recordingName === 'call-1',
    );
});

it('on B no-answer: releases B and leaves the caller with A (Fold B)', function () {
    $router = fakeAgentRouter(6);
    $session = new RecordingSession('caller-leg', 'call-1', 'snoop-said', 'snoop-heard');

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-leg');
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent', null)->andReturn('agent-leg');
    $telephony->shouldReceive('join')->once()->andReturn('conv-1');
    $telephony->shouldReceive('startRecording')->once()->andReturn($session);
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent')->andReturn('transfer-leg');
    // No surgery — B never answered. The call later ends normally with A as the agent.
    $telephony->shouldNotReceive('addToBridge');
    $telephony->shouldReceive('stopRecording')->once()->with($session);
    $telephony->shouldReceive('hangup')->once()->with('agent-leg');   // A is still the survivor
    $telephony->shouldReceive('endConversation')->once()->with('conv-1');

    $switchboard = new Switchboard($telephony);
    $flow = new CallToAgentFlow($telephony, $switchboard);

    $flow->handle(stasisStart('caller-leg', []));
    $flow->handle(stasisStart('agent-leg', ['agent']));
    $flow->beginTransfer(3);
    $flow->handle(channelDestroyed('transfer-leg'));   // B rings out / declines
    $flow->handle(channelDestroyed('caller-leg'));      // the customer hangs up — A was still on the call

    // B's reservation was released (Fold B); A's connected tag was never released
    // (it was cleared, not released, at connect — the agent's own screen owns it).
    expect($router->released)->toBe([[3, 6]]);
});

it('when nobody is free at transfer time: touches nothing — the caller stays with A', function () {
    // A's reserve succeeds (the call connects); B's reserve finds nobody free.
    $router = new class extends AgentRouter
    {
        /** @var array<int, int> */
        public array $reserved = [];

        /** @var array<int, array{int, int}> */
        public array $released = [];

        private int $calls = 0;

        public function reserveFreeAgent(int $tenantId): ?int
        {
            $this->reserved[] = $tenantId;

            return $this->calls++ === 0 ? 6 : null;   // A gets agent 6; B finds nobody
        }

        public function releaseReservation(int $tenantId, int $userId): void
        {
            $this->released[] = [$tenantId, $userId];
        }
    };
    app()->instance(AgentRouter::class, $router);

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

    $switchboard = new Switchboard($telephony);
    $flow = new CallToAgentFlow($telephony, $switchboard);

    $flow->handle(stasisStart('caller-leg', []));
    $flow->handle(stasisStart('agent-leg', ['agent']));
    $flow->beginTransfer(3);                          // nobody free -> no-op
    $flow->handle(channelDestroyed('caller-leg'));    // A still has the caller

    expect($router->reserved)->toBe([3, 3]);          // A's reserve + the failed B reserve
    expect($router->released)->toBe([]);              // nothing to release — B was never reserved
});

it('when the caller hangs up mid-transfer-ring: ends the call cleanly and releases B', function () {
    $router = fakeAgentRouter(6);
    $session = new RecordingSession('caller-leg', 'call-1', 'snoop-said', 'snoop-heard');

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-leg');
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent', null)->andReturn('agent-leg');
    $telephony->shouldReceive('join')->once()->andReturn('conv-1');
    $telephony->shouldReceive('startRecording')->once()->andReturn($session);
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent')->andReturn('transfer-leg');
    // The caller leaves: the still-ringing B is hung up, the recording stopped, A (the
    // survivor) hung up, the conversation closed. The caller is never left alone.
    $telephony->shouldReceive('hangup')->once()->with('transfer-leg');
    $telephony->shouldReceive('stopRecording')->once()->with($session);
    $telephony->shouldReceive('hangup')->once()->with('agent-leg');
    $telephony->shouldReceive('endConversation')->once()->with('conv-1');

    $switchboard = new Switchboard($telephony);
    $flow = new CallToAgentFlow($telephony, $switchboard);

    $flow->handle(stasisStart('caller-leg', []));
    $flow->handle(stasisStart('agent-leg', ['agent']));
    $flow->beginTransfer(3);
    $flow->handle(channelDestroyed('caller-leg'));   // the customer abandons mid-ring

    expect($router->released)->toBe([[3, 6]]);
});

it('when A hangs up mid-transfer-ring: ends the call cleanly rather than stranding the caller, and releases B', function () {
    $router = fakeAgentRouter(6);
    $session = new RecordingSession('caller-leg', 'call-1', 'snoop-said', 'snoop-heard');

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-leg');
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent', null)->andReturn('agent-leg');
    $telephony->shouldReceive('join')->once()->andReturn('conv-1');
    $telephony->shouldReceive('startRecording')->once()->andReturn($session);
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent')->andReturn('transfer-leg');
    $telephony->shouldReceive('hangup')->once()->with('transfer-leg');   // B (still ringing)
    $telephony->shouldReceive('stopRecording')->once()->with($session);
    $telephony->shouldReceive('hangup')->once()->with('caller-leg');     // the caller is the survivor
    $telephony->shouldReceive('endConversation')->once()->with('conv-1');

    $switchboard = new Switchboard($telephony);
    $flow = new CallToAgentFlow($telephony, $switchboard);

    $flow->handle(stasisStart('caller-leg', []));
    $flow->handle(stasisStart('agent-leg', ['agent']));
    $flow->beginTransfer(3);
    $flow->handle(channelDestroyed('agent-leg'));   // A drops mid-ring (shouldn't, but)

    expect($router->released)->toBe([[3, 6]]);
});

it('only reports serving the connected agent, and ignores a transfer before the call connects', function () {
    fakeAgentRouter(6);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-leg');
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent', null)->andReturn('agent-leg');

    $switchboard = new Switchboard($telephony);
    $flow = new CallToAgentFlow($telephony, $switchboard);

    $flow->handle(stasisStart('caller-leg', []));   // RingingAgent — A not connected yet

    expect($flow->isServingAgent(6))->toBeFalse();  // no agent is serving until connect
    $flow->beginTransfer(3);                          // ignored — not InCall (no join / no B ring)

    // Now connect the call:
    $telephony->shouldReceive('join')->once()->andReturn('conv-1');
    $telephony->shouldReceive('startRecording')->once()->andReturn(new RecordingSession('caller-leg', 'call-1', 's', 'h'));
    $flow->handle(stasisStart('agent-leg', ['agent']));

    expect($flow->isServingAgent(6))->toBeTrue();
    expect($flow->isServingAgent(99))->toBeFalse();
});

it('retains the dialing agent on an outbound call (threaded tag) so it too can be transferred', function () {
    fakeAgentRouter(9);
    config()->set('telephony.outbound.dial_prefix', 'PJSIP/');
    config()->set('telephony.outbound.caller_id', '1800555000');

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1002', 'outbound', '1800555000')->andReturn('customer-leg');
    $telephony->shouldReceive('join')->once()->andReturn('conv-1');
    $telephony->shouldReceive('startRecording')->once()->andReturn(new RecordingSession('customer-leg', 'call-1', 's', 'h'));
    // The transfer rings B — proof the outbound call is transferable (serving agent retained).
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent')->andReturn('transfer-leg');

    $switchboard = new Switchboard($telephony);
    $flow = new CallToAgentFlow($telephony, $switchboard);

    // The agent leg carries customer number (args[1]), the call UUID (args[2]), and the
    // dialing agent's user id (args[3] = 7) — the B2.4a thread.
    $flow->handle(stasisStart('agent-leg', ['agent', '1002', 'the-uuid', '7']));
    $flow->handle(stasisStart('customer-leg', ['outbound']));   // -> InCall, serving agent = 7

    expect($flow->isServingAgent(7))->toBeTrue();
    $flow->beginTransfer(3);
});
