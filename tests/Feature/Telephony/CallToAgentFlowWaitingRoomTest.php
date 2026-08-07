<?php

use App\Telephony\Flows\CallToAgentFlow;
use App\Telephony\Flows\Switchboard;
use App\Telephony\TelephonyProvider;
use Illuminate\Support\Facades\Queue;

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
    config()->set('telephony.agent.endpoint', 'PJSIP/1003');
    config()->set('telephony.agent.directory', []);
    fakeNumberDirectory();
    Queue::fake();
});

it('connects the waiting caller to the next agent who frees up, music off before the phone rings (QD-3)', function () {
    $router = fakeAgentRouter(null);   // nobody free when the call arrives

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-leg');
    $telephony->shouldReceive('startHoldMusic')->once()->with('caller-leg');

    $switchboard = new Switchboard($telephony);
    $flow = new CallToAgentFlow($telephony, $switchboard);
    $flow->handle(stasisStart('caller-leg', []));

    // A sweep while the floor is still full changes nothing at all — the caller keeps
    // holding, and nothing is asked of the phone system.
    $flow->tryAgain();

    // Now an agent frees up. Ordered on purpose: the music has to stop BEFORE the
    // agent's leg is placed, or the caller is still hearing music when they connect.
    $router->agentId = 6;
    $ordered = [];
    $telephony->shouldReceive('stopHoldMusic')->once()->with('caller-leg')
        ->andReturnUsing(function () use (&$ordered): void {
            $ordered[] = 'music off';
        });
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1003', 'agent', null, 20)
        ->andReturnUsing(function () use (&$ordered): string {
            $ordered[] = 'ring';

            return 'agent-leg';
        });

    $flow->tryAgain();

    expect($ordered)->toBe(['music off', 'ring'])
        ->and($router->reserved)->toHaveCount(3);   // the arrival, the fruitless sweep, the one that landed
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
    $telephony->shouldNotReceive('startHoldMusic');
    $telephony->shouldNotReceive('stopHoldMusic');

    $switchboard = new Switchboard($telephony);
    $flow = new CallToAgentFlow($telephony, $switchboard);
    $flow->handle(stasisStart('caller-leg', []));   // ringing an agent, not waiting

    $flow->tryAgain();
    $flow->tryAgain();
});
