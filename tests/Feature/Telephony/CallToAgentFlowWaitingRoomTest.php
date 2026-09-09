<?php

use App\Telephony\Flows\CallToAgentFlow;
use App\Telephony\Flows\Switchboard;
use App\Telephony\RecordingSession;
use App\Telephony\TelephonyException;
use App\Telephony\TelephonyProvider;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => fakeAgentDirectory());   // SEC-1 slice 4: the reserved agent has a phone

/**
 * B2.3b-i — the waiting room. The system used to hang up on a caller nobody could
 * take; now they hold with music until a desk frees up, they give up, or the client's
 * maximum hold time runs out.
 *
 * These prove the MECHANICS against a mocked provider and a stubbed board — who gets
 * rung, when the music starts and stops, and who is deliberately not rung again. The
 * missed-call ROW those endings write is proven against a real database in
 * MissedCallRecordTest; the sweep that drives all of this from the listener's heartbeat
 * is proven in SwitchboardTest.
 */
beforeEach(function () {
    fakeNumberDirectory();
    Queue::fake();
});

it('connects the waiting caller to the next agent who frees up, music playing right through the ring (QD-3, S88 review #3)', function () {
    $router = fakeAgentRouter(null);   // nobody free when the call arrives
    $session = new RecordingSession('caller-leg', 'call-1', 'said', 'heard');

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-leg');
    $telephony->shouldReceive('startHoldMusic')->once()->with('caller-leg');

    $switchboard = new Switchboard($telephony);
    $flow = new CallToAgentFlow($telephony, $switchboard);
    $flow->handle(stasisStart('caller-leg', []));

    // A sweep while the floor is still full changes nothing at all — the caller keeps
    // holding, and nothing is asked of the phone system.
    $flow->tryAgain();

    // Now an agent frees up. Ordered on purpose, and the order is the POINT: the music
    // must still be playing while that agent's phone rings, because the caller is not on
    // the agent's line yet. Stopping it at ring-time bought them silence for the whole
    // ring window — twenty seconds of dead air that reads as a dropped call.
    $router->agentId = 6;
    $ordered = [];
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent', null, 20)
        ->andReturnUsing(function () use (&$ordered): string {
            $ordered[] = 'ring';

            return 'agent-leg';
        });
    $telephony->shouldReceive('stopHoldMusic')->once()->with('caller-leg')
        ->andReturnUsing(function () use (&$ordered): void {
            $ordered[] = 'music off';
        });
    $telephony->shouldReceive('join')->once()->andReturnUsing(function () use (&$ordered): string {
        $ordered[] = 'joined';

        return 'conv-1';
    });
    $telephony->shouldReceive('startRecording')->once()->andReturn($session);

    $flow->tryAgain();                                   // the sweep rings them, music still on
    $flow->handle(stasisStart('agent-leg', ['agent']));  // the agent picks up

    expect($ordered)->toBe(['ring', 'music off', 'joined'])
        ->and($router->reserved)->toHaveCount(3);   // the arrival, the fruitless sweep, the one that landed
});

it('releases the agent booked by a sweep that never got as far as placing the leg (S88 review #1)', function () {
    $router = fakeAgentRouter(null);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-leg');
    $telephony->shouldReceive('startHoldMusic')->once()->with('caller-leg');

    $switchboard = new Switchboard($telephony);
    $flow = new CallToAgentFlow($telephony, $switchboard);
    $flow->handle(stasisStart('caller-leg', []));

    // An agent frees up, the sweep books them on the board — and the caller hangs up in
    // that same instant, so placing their leg comes back "channel not found". Nothing
    // carries the reservation yet, which is exactly how an agent used to end up tagged
    // On a call with no way back short of a database edit.
    $router->agentId = 6;
    $telephony->shouldReceive('placeCall')->once()->andThrow(new TelephonyException('Channel not found'));
    $telephony->shouldReceive('hangup')->once()->with('caller-leg');

    $flow->tryAgain();

    expect($router->released)->toBe([[3, 6]]);   // the board tag was handed back
});

it('does not ring the same agent again for a caller they already let ring out (QD-4 skip list)', function () {
    $router = fakeAgentRouter(6);   // one agent on the floor, and they never pick up

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('placeCall')->once()->andReturn('agent-leg');   // rung exactly ONCE
    $telephony->shouldReceive('startHoldMusic')->once()->with('caller-leg');
    $telephony->shouldNotReceive('hangup');

    $switchboard = new Switchboard($telephony);
    $flow = new CallToAgentFlow($telephony, $switchboard);
    $flow->handle(stasisStart('caller-leg', []));
    $flow->handle(channelDestroyed('agent-leg'));   // their phone rang out

    // Sweeps keep coming, and that same silent desk is skipped every time — without
    // this the caller cycles between music and one unanswered phone forever.
    $flow->tryAgain();
    $flow->tryAgain();

    expect($router->skipped)->toBe([[], [6], [6]]);   // arrival asked with an empty list; the sweeps exclude agent 6
});

it('rings that same desk again once its cooling-off has passed, so a small floor is no longer a cap on rings (S88)', function () {
    $router = fakeAgentRouter(6);   // a one-person floor

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('startHoldMusic')->once()->with('caller-leg');
    $telephony->shouldReceive('placeCall')->twice()->andReturn('agent-leg');   // rung, and rung AGAIN
    $telephony->shouldNotReceive('hangup');

    $switchboard = new Switchboard($telephony);
    $flow = new CallToAgentFlow($telephony, $switchboard);
    $flow->handle(stasisStart('caller-leg', []));
    $flow->handle(channelDestroyed('agent-leg'));   // their phone rang out

    $flow->tryAgain();   // still inside the cooling-off — skipped, exactly as before

    // One ring later they are an ordinary candidate again. Writing that desk off for the
    // whole call used to mean this caller could never connect at all: they would hold out
    // the rest of the maximum with the only person who could take the call sitting there
    // Ready the entire time, and then be hung up on.
    $this->travel(21)->seconds();
    $flow->tryAgain();

    expect($router->skipped)->toBe([[], [6], []]);
});

it('stops waiting and ends the call once the caller passes the maximum hold time (QD-7)', function () {
    fakeAgentRouter(null);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('startHoldMusic')->once();
    $telephony->shouldReceive('hangup')->once()->with('caller-leg');

    $switchboard = new Switchboard($telephony);
    $flow = new CallToAgentFlow($telephony, $switchboard);
    $flow->handle(stasisStart('caller-leg', []));

    $this->travel(181)->seconds();   // past the 180-second default
    $flow->tryAgain();

    // The handler is spent: a later sweep must not hang the (already dead) leg up twice.
    $flow->tryAgain();
});

it('stops waiting even while a desk is ringing, so the cap is not overshot by a whole ring (S88 review #4)', function () {
    $router = fakeAgentRouter(6);   // an agent is free, so this caller's phone-ring starts at once

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('startHoldMusic')->once();
    $telephony->shouldReceive('placeCall')->once()->andReturn('agent-leg');
    $telephony->shouldReceive('hangup')->once()->with('agent-leg');
    $telephony->shouldReceive('hangup')->once()->with('caller-leg');

    $switchboard = new Switchboard($telephony);
    $flow = new CallToAgentFlow($telephony, $switchboard);
    $flow->handle(stasisStart('caller-leg', []));

    // The cap has passed while that agent's phone is still ringing. The sweep used to
    // ignore a ringing call entirely, so the ring ran to its own end and only THEN was the
    // caller let go — every client's configured maximum overshot by a full ring.
    $this->travel(181)->seconds();
    $flow->tryAgain();

    // The ringing desk is dropped with the caller, and handed back to the board — being
    // caught mid-ring by the cap must not leave that agent tagged "On a call".
    expect($router->released)->toBe([[3, 6]])
        ->and($switchboard->activeCallCount())->toBe(0);
});

it('keeps holding a caller who is still inside the maximum hold time', function () {
    fakeAgentRouter(null);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('startHoldMusic')->once();
    $telephony->shouldNotReceive('hangup');

    $switchboard = new Switchboard($telephony);
    $flow = new CallToAgentFlow($telephony, $switchboard);
    $flow->handle(stasisStart('caller-leg', []));

    $this->travel(179)->seconds();   // one second short of the cap
    $flow->tryAgain();
});

it('is a no-op on a call that is not waiting — every other call on the switch', function () {
    fakeAgentRouter(6);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('placeCall')->once()->andReturn('agent-leg');
    // Music starts ONCE, for the first ring (S88) — and the sweeps below add nothing to
    // it. Stopping it is what would mean the sweep had meddled with a call that is not
    // waiting, so that is the assertion that carries the test's point.
    $telephony->shouldReceive('startHoldMusic')->once();
    $telephony->shouldNotReceive('stopHoldMusic');

    $switchboard = new Switchboard($telephony);
    $flow = new CallToAgentFlow($telephony, $switchboard);
    $flow->handle(stasisStart('caller-leg', []));   // ringing an agent, not waiting

    $flow->tryAgain();
    $flow->tryAgain();
});

/*
| S87, found live on staging — a leg ending arrives under TWO names, and the one an
| outside caller's own hang-up produces is "this leg has left the app" (StasisEnd), not
| "this call was destroyed" (ChannelDestroyed). Only the second was ever handled, so a
| caller who hung up while holding was never noticed: they kept their place in the
| waiting room until the maximum-hold timer fired and were then recorded as "we stopped
| waiting" rather than "they gave up". Every case below feeds the leaving name only.
*/

it('notices a waiting caller who hangs up, when the engine reports it as the leg LEAVING', function () {
    fakeAgentRouter(null);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('startHoldMusic')->once();
    $telephony->shouldNotReceive('hangup');   // they are already gone; nothing left to hang up

    $switchboard = new Switchboard($telephony);
    $switchboard->handle(stasisStart('caller-leg', []));
    $switchboard->handle(stasisEnd('caller-leg'));

    // The call is let go THERE AND THEN. Before the fix it stayed live, holding a caller
    // who had already hung up, until the maximum-hold timer ended it minutes later.
    expect($switchboard->activeCallCount())->toBe(0);
});

it('notices an agent whose ringing leg leaves, and sends the caller to the waiting room', function () {
    fakeAgentRouter(6);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('placeCall')->once()->andReturn('agent-leg');
    $telephony->shouldReceive('startHoldMusic')->once()->with('caller-leg');
    $telephony->shouldNotReceive('hangup');

    $switchboard = new Switchboard($telephony);
    $flow = new CallToAgentFlow($telephony, $switchboard);
    $flow->handle(stasisStart('caller-leg', []));
    $flow->handle(stasisEnd('agent-leg'));

    // Still held, and that agent is on the skip list — same as the destroyed-name path.
    $flow->tryAgain();
});

it('ends a connected call when the caller\'s leg leaves', function () {
    fakeAgentRouter(6);
    $session = new RecordingSession('caller-leg', 'call-1', 'said', 'heard');

    $telephony = fakeTelephony();
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('placeCall')->once()->andReturn('agent-leg');
    $telephony->shouldReceive('join')->once()->andReturn('conv-1');
    $telephony->shouldReceive('startRecording')->once()->andReturn($session);
    $telephony->shouldReceive('stopRecording')->once();
    $telephony->shouldReceive('hangup')->once()->with('agent-leg');   // the survivor is dropped
    $telephony->shouldReceive('endConversation')->once();

    $switchboard = new Switchboard($telephony);
    $switchboard->handle(stasisStart('caller-leg', []));
    $switchboard->handle(stasisStart('agent-leg', ['agent']));
    $switchboard->handle(stasisEnd('caller-leg'));

    expect($switchboard->activeCallCount())->toBe(0);
});

it('handles both names for the same leg without acting twice', function () {
    fakeAgentRouter(null);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('startHoldMusic')->once();

    $switchboard = new Switchboard($telephony);
    $switchboard->handle(stasisStart('caller-leg', []));
    $switchboard->handle(stasisEnd('caller-leg'));
    $switchboard->handle(channelDestroyed('caller-leg'));   // the engine sends both on some paths

    expect($switchboard->activeCallCount())->toBe(0);
});
