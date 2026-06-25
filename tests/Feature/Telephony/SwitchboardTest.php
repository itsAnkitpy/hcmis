<?php

use App\Jobs\MergeCallRecordingJob;
use App\Telephony\AriConnectionLost;
use App\Telephony\Flows\Switchboard;
use App\Telephony\RecordingSession;
use App\Telephony\TelephonyProvider;
use Illuminate\Support\Facades\Queue;

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
    config()->set('telephony.agent.endpoint', 'PJSIP/1003');
    Queue::fake();
});

it('tracks two simultaneous calls independently, routing each interleaved event to the right handler', function () {
    $sessionA = new RecordingSession('caller-A', 'call-A', 'A-said', 'A-heard');
    $sessionB = new RecordingSession('caller-B', 'call-B', 'B-said', 'B-heard');

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-A');
    $telephony->shouldReceive('answer')->once()->with('caller-B');
    $telephony->shouldReceive('placeCall')->twice()->with('PJSIP/1003', 'agent', null)->andReturn('agent-A', 'agent-B');
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

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-A');
    $telephony->shouldReceive('answer')->once()->with('caller-B');   // the OLD one-handler code never answered B
    $telephony->shouldReceive('placeCall')->twice()->with('PJSIP/1003', 'agent', null)->andReturn('agent-A', 'agent-B');
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

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-A');
    $telephony->shouldReceive('answer')->once()->with('caller-B');
    // A places fine; B's place throws an UNEXPECTED error (not a TelephonyException), so it
    // escapes the handler's own catch and hits the switchboard backstop. The differing caller
    // number is only there to tell the two placeCall expectations apart.
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent', null)->andReturn('agent-A');
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent', '555')->andThrow(new RuntimeException('boom'));
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
    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-A')->andThrow(new AriConnectionLost('pipe gone'));

    $switchboard = new Switchboard($telephony);

    // AriConnectionLost is everyone's problem (it drives the listener's reconnect), so the
    // per-call backstop must re-throw it, not treat it as one call's failure.
    expect(fn () => $switchboard->handle(stasisStart('caller-A', [])))
        ->toThrow(AriConnectionLost::class);
});

it('still merges a recording that finishes after its call handler is disposed (FD-4)', function () {
    $session = new RecordingSession('caller-A', 'call-A', 'A-said', 'A-heard');

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-A');
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent', null)->andReturn('agent-A');
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
        fn (MergeCallRecordingJob $job): bool => $job->callId === 'caller-A' && $job->recordingName === 'call-A',
    );
});

it('merges an inbound recording even when the finished events arrive BEFORE teardown (the CP-B2.1 race)', function () {
    // The bug CP-B2.1 exposed: on an inbound call the recorded (caller) leg hangs up first,
    // so Asterisk emits "recording finished" for both taps IMMEDIATELY — before the caller's
    // ChannelDestroyed reaches us. The merge must already be registered (at connect, not at
    // teardown) or those events land on an empty notebook and the recording is lost.
    $session = new RecordingSession('caller-A', 'call-A', 'A-said', 'A-heard');

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-A');
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent', null)->andReturn('agent-A');
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
        fn (MergeCallRecordingJob $job): bool => $job->callId === 'caller-A' && $job->recordingName === 'call-A',
    );

    $switchboard->handle(channelDestroyed('caller-A'));          // teardown lands after — clean
    expect($switchboard->activeCallCount())->toBe(0);
});

it('never makes a handler for a snoop leg and never routes its events', function () {
    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldNotReceive('answer');
    $telephony->shouldNotReceive('placeCall');

    $switchboard = new Switchboard($telephony);

    $switchboard->handle(stasisStart('snoop-leg', ['snoop']));  // our recording tap — infrastructure
    $switchboard->handle(channelDestroyed('snoop-leg'));        // never in the phone-book → dropped

    expect($switchboard->activeCallCount())->toBe(0);
});

it('forgets a disposed call legs, so a later event for a dead leg matches nothing and is dropped', function () {
    $session = new RecordingSession('caller-A', 'call-A', 'A-said', 'A-heard');

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-A');
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent', null)->andReturn('agent-A');
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
