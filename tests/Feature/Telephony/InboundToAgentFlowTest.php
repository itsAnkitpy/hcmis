<?php

use App\Jobs\MergeCallRecordingJob;
use App\Telephony\Flows\InboundToAgentFlow;
use App\Telephony\RecordingSession;
use App\Telephony\TelephonyProvider;
use Illuminate\Support\Facades\Queue;

/**
 * The B4 v1 call flow (D5), event-driven: an outside caller reaches a browser
 * agent. These prove the sequence against a mocked provider — no Asterisk, no
 * network — so the risky rewrite is covered before the live CP2a checkpoint.
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

    $flow = new InboundToAgentFlow($telephony);

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

    $flow = new InboundToAgentFlow($telephony);

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

    $flow = new InboundToAgentFlow($telephony);

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

    $flow = new InboundToAgentFlow($telephony);

    $flow->handle(stasisStart('caller-leg', [], '9991234567'));   // caller presents a number

    Queue::assertNothingPushed();
});

it('presents no caller-ID when the caller is anonymous (empty number)', function () {
    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-leg');
    $telephony->shouldReceive('placeCall')->once()
        ->with('PJSIP/1003', 'agent', null)
        ->andReturn('agent-leg');

    $flow = new InboundToAgentFlow($telephony);

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

    $flow = new InboundToAgentFlow($telephony);

    $flow->handle(stasisStart('caller-leg', []));
    $flow->handle(stasisStart('agent-leg', ['agent']));
    $flow->handle(stasisStart('second-caller', []));      // arrives mid-call — announced, not driven

    Queue::assertNothingPushed();
});
