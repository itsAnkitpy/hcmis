<?php

use App\Jobs\MergeCallRecordingJob;
use App\Telephony\Flows\CallToAgentFlow;
use App\Telephony\RecordingSession;
use App\Telephony\TelephonyProvider;
use Illuminate\Support\Facades\Queue;

/**
 * The call-to-agent flow, event-driven, both directions. Inbound: an outside
 * caller reaches a browser agent (B4 CP2a). Outbound: the agent's leg arrives
 * first carrying the customer number, and the flow dials the customer (B-outbound
 * M2). These prove the sequences against a mocked provider — no Asterisk, no
 * network — so the risky logic is covered before the live checkpoints.
 *
 * The flow is fed the same raw event shapes the listener reads off ARI; only
 * the fields the flow actually inspects are built here.
 */

/**
 * @param  array<int, string>  $args
 * @return array<string, mixed>
 */
function stasisStart(string $legId, array $args, ?string $callerNumber = null): array
{
    $channel = ['id' => $legId];

    if ($callerNumber !== null) {
        $channel['caller'] = ['number' => $callerNumber];
    }

    return ['type' => 'StasisStart', 'args' => $args, 'channel' => $channel];
}

/**
 * @return array<string, mixed>
 */
function channelDestroyed(string $legId): array
{
    return ['type' => 'ChannelDestroyed', 'channel' => ['id' => $legId]];
}

/**
 * @return array<string, mixed>
 */
function recordingFinished(string $name): array
{
    return ['type' => 'RecordingFinished', 'recording' => ['name' => $name]];
}

beforeEach(function () {
    config()->set('telephony.agent.endpoint', 'PJSIP/1003');
    Queue::fake();
});

it('connects an answering agent and merges the call once both recordings finish', function () {
    $session = new RecordingSession('caller-leg', 'call-1', 'snoop-said', 'snoop-heard');

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-leg');
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent', null)->andReturn('agent-leg');
    $telephony->shouldReceive('join')->once()->with('caller-leg', 'agent-leg')->andReturn('conv-1');
    $telephony->shouldReceive('startRecording')->once()
        ->with('caller-leg', Mockery::on(fn (string $name): bool => str_starts_with($name, 'call-')))
        ->andReturn($session);
    $telephony->shouldReceive('stopRecording')->once()->with($session);
    $telephony->shouldReceive('hangup')->once()->with('agent-leg');         // the survivor
    $telephony->shouldReceive('endConversation')->once()->with('conv-1');

    $flow = new CallToAgentFlow($telephony);

    $flow->handle(stasisStart('caller-leg', []));        // caller dials in
    $flow->handle(stasisStart('agent-leg', ['agent']));   // agent picks up
    $flow->handle(channelDestroyed('caller-leg'));        // caller hangs up

    Queue::assertNothingPushed();                         // not until both files are confirmed

    $flow->handle(recordingFinished('call-1-said'));
    Queue::assertNothingPushed();                         // one side is not enough

    $flow->handle(recordingFinished('call-1-heard'));

    Queue::assertPushed(
        MergeCallRecordingJob::class,
        fn (MergeCallRecordingJob $job): bool => $job->callId === 'caller-leg' && $job->recordingName === 'call-1',
    );
});

it('hangs up the agent leg when the caller abandons before pickup', function () {
    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-leg');
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent', null)->andReturn('agent-leg');
    $telephony->shouldReceive('hangup')->once()->with('agent-leg');
    $telephony->shouldNotReceive('join');
    $telephony->shouldNotReceive('startRecording');

    $flow = new CallToAgentFlow($telephony);

    $flow->handle(stasisStart('caller-leg', []));
    $flow->handle(channelDestroyed('caller-leg'));        // gives up while the agent rings

    Queue::assertNothingPushed();
});

it('hangs up the caller when the agent never answers', function () {
    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-leg');
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent', null)->andReturn('agent-leg');
    $telephony->shouldReceive('hangup')->once()->with('caller-leg');
    $telephony->shouldNotReceive('join');
    $telephony->shouldNotReceive('startRecording');

    $flow = new CallToAgentFlow($telephony);

    $flow->handle(stasisStart('caller-leg', []));
    $flow->handle(channelDestroyed('agent-leg'));         // Asterisk's 30s originate timeout fired

    Queue::assertNothingPushed();
});

it('passes the caller number to placeCall as the agent leg caller-ID (B4 D4)', function () {
    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-leg');
    $telephony->shouldReceive('placeCall')->once()
        ->with('PJSIP/1003', 'agent', '9991234567')
        ->andReturn('agent-leg');

    $flow = new CallToAgentFlow($telephony);

    $flow->handle(stasisStart('caller-leg', [], '9991234567'));   // caller presents a number

    Queue::assertNothingPushed();
});

it('presents no caller-ID when the caller is anonymous (empty number)', function () {
    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-leg');
    $telephony->shouldReceive('placeCall')->once()
        ->with('PJSIP/1003', 'agent', null)
        ->andReturn('agent-leg');

    $flow = new CallToAgentFlow($telephony);

    $flow->handle(stasisStart('caller-leg', [], ''));   // anonymous caller → empty number

    Queue::assertNothingPushed();
});

it('does not drive a second caller while a call is already in progress', function () {
    $session = new RecordingSession('caller-leg', 'call-1', 'snoop-said', 'snoop-heard');

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-leg');   // only the first caller
    $telephony->shouldReceive('placeCall')->once()->andReturn('agent-leg');
    $telephony->shouldReceive('join')->once()->andReturn('conv-1');
    $telephony->shouldReceive('startRecording')->once()->andReturn($session);

    $flow = new CallToAgentFlow($telephony);

    $flow->handle(stasisStart('caller-leg', []));
    $flow->handle(stasisStart('agent-leg', ['agent']));
    $flow->handle(stasisStart('second-caller', []));      // arrives mid-call — announced, not driven

    Queue::assertNothingPushed();
});

/*
|--------------------------------------------------------------------------
| Outbound (agent-first) — B-outbound M2 / CP-O1
|--------------------------------------------------------------------------
|
| The web console places the AGENT leg, tagging it ['agent', <customerNumber>].
| When that leg arrives the flow dials the CUSTOMER (prefix + number, the single
| outbound caller-ID), and the customer answering joins + records exactly as
| inbound does — the customer is the internal callerLegId.
|
*/

it('dials the customer when the agent leg arrives carrying the number, then joins + records on answer (outbound)', function () {
    config()->set('telephony.outbound.dial_prefix', 'PJSIP/');
    config()->set('telephony.outbound.caller_id', '1800555000');

    $session = new RecordingSession('customer-leg', 'call-1', 'snoop-said', 'snoop-heard');

    $telephony = Mockery::mock(TelephonyProvider::class);
    // The flow does NOT place the agent leg (the console did); it places the
    // customer leg using the number that rode in as args[1], with the prefix and
    // the configured outbound caller-ID.
    $telephony->shouldReceive('placeCall')->once()
        ->with('PJSIP/1002', 'outbound', '1800555000')
        ->andReturn('customer-leg');
    $telephony->shouldReceive('join')->once()->with('customer-leg', 'agent-leg')->andReturn('conv-1');
    $telephony->shouldReceive('startRecording')->once()
        ->with('customer-leg', Mockery::on(fn (string $name): bool => str_starts_with($name, 'call-')))
        ->andReturn($session);
    $telephony->shouldReceive('stopRecording')->once()->with($session);
    $telephony->shouldReceive('hangup')->once()->with('agent-leg');          // the survivor
    $telephony->shouldReceive('endConversation')->once()->with('conv-1');

    $flow = new CallToAgentFlow($telephony);

    $flow->handle(stasisStart('agent-leg', ['agent', '1002']));   // agent leg up, carrying the customer number
    $flow->handle(stasisStart('customer-leg', ['outbound']));     // customer picks up
    $flow->handle(channelDestroyed('customer-leg'));              // customer hangs up

    Queue::assertNothingPushed();                                 // not until both files are confirmed

    $flow->handle(recordingFinished('call-1-said'));
    $flow->handle(recordingFinished('call-1-heard'));

    Queue::assertPushed(
        MergeCallRecordingJob::class,
        fn (MergeCallRecordingJob $job): bool => $job->callId === 'customer-leg' && $job->recordingName === 'call-1',
    );
});

it('threads the injected UUID (args[2]) as the recording callId so RecordingReady carries our correlation id (CP-B3-2 D3)', function () {
    config()->set('telephony.outbound.dial_prefix', 'PJSIP/');
    config()->set('telephony.outbound.caller_id', '1800555000');

    $session = new RecordingSession('customer-leg', 'call-1', 'snoop-said', 'snoop-heard');

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('placeCall')->once()
        ->with('PJSIP/1002', 'outbound', '1800555000')
        ->andReturn('customer-leg');
    $telephony->shouldReceive('join')->once()->andReturn('conv-1');
    $telephony->shouldReceive('startRecording')->once()->andReturn($session);
    $telephony->shouldReceive('stopRecording')->once()->with($session);
    $telephony->shouldReceive('hangup')->once()->with('agent-leg');
    $telephony->shouldReceive('endConversation')->once()->with('conv-1');

    $flow = new CallToAgentFlow($telephony);

    // The agent leg now carries the customer number AND the call's UUID (args[2]).
    $flow->handle(stasisStart('agent-leg', ['agent', '1002', 'the-uuid']));
    $flow->handle(stasisStart('customer-leg', ['outbound']));
    $flow->handle(channelDestroyed('customer-leg'));

    $flow->handle(recordingFinished('call-1-said'));
    $flow->handle(recordingFinished('call-1-heard'));

    // The merge (and thus RecordingReady) is keyed by the UUID, not the leg id —
    // that is what lets the queued listener find the calls row by correlation_id.
    Queue::assertPushed(
        MergeCallRecordingJob::class,
        fn (MergeCallRecordingJob $job): bool => $job->callId === 'the-uuid' && $job->recordingName === 'call-1',
    );
});

it('does not join until the outbound customer actually answers', function () {
    config()->set('telephony.outbound.dial_prefix', 'PJSIP/');
    config()->set('telephony.outbound.caller_id', null);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('placeCall')->once()
        ->with('PJSIP/1002', 'outbound', null)
        ->andReturn('customer-leg');
    $telephony->shouldNotReceive('join');
    $telephony->shouldNotReceive('startRecording');

    $flow = new CallToAgentFlow($telephony);

    $flow->handle(stasisStart('agent-leg', ['agent', '1002']));   // customer is now ringing, not up

    Queue::assertNothingPushed();
});

it('tears down the agent leg when the outbound customer never answers (no-answer, CP-O2)', function () {
    config()->set('telephony.outbound.dial_prefix', 'PJSIP/');
    config()->set('telephony.outbound.caller_id', null);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('placeCall')->once()
        ->with('PJSIP/1002', 'outbound', null)
        ->andReturn('customer-leg');
    // The customer leg dying while ringing is the no-answer signal: drop the
    // already-answered agent leg, which flips the browser to wrap-up (D5). No
    // join, no recording — nothing was ever connected.
    $telephony->shouldReceive('hangup')->once()->with('agent-leg');
    $telephony->shouldNotReceive('join');
    $telephony->shouldNotReceive('startRecording');

    $flow = new CallToAgentFlow($telephony);

    $flow->handle(stasisStart('agent-leg', ['agent', '1002']));   // agent up, customer dialed
    $flow->handle(channelDestroyed('customer-leg'));              // customer ring timed out

    Queue::assertNothingPushed();
});

it('cancels the customer leg when the agent abandons before the customer answers (CP-O2)', function () {
    config()->set('telephony.outbound.dial_prefix', 'PJSIP/');
    config()->set('telephony.outbound.caller_id', null);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('placeCall')->once()
        ->with('PJSIP/1002', 'outbound', null)
        ->andReturn('customer-leg');
    // The agent hangs up while the customer is still ringing: cancel the
    // still-ringing customer leg so it is not left orphaned.
    $telephony->shouldReceive('hangup')->once()->with('customer-leg');
    $telephony->shouldNotReceive('join');
    $telephony->shouldNotReceive('startRecording');

    $flow = new CallToAgentFlow($telephony);

    $flow->handle(stasisStart('agent-leg', ['agent', '1002']));   // agent up, customer ringing
    $flow->handle(channelDestroyed('agent-leg'));                 // agent abandons mid-ring

    Queue::assertNothingPushed();
});
