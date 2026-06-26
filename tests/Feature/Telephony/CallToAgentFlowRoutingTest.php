<?php

use App\Telephony\Flows\CallToAgentFlow;
use App\Telephony\Flows\Switchboard;
use App\Telephony\RecordingSession;
use App\Telephony\TelephonyProvider;
use Illuminate\Support\Facades\Queue;

/**
 * B2.2b — the inbound flow picks a free agent and rings THEM (RD-1..RD-5 + Fold B).
 * The board read + atomic reserve/release are proven against the DB in AgentRouterTest;
 * here we prove the FLOW wiring against a mocked provider + a stub router: it reads the
 * company label off the call, reserves BEFORE ringing, rings the RESOLVED endpoint,
 * releases on both no-answer paths, and ends cleanly when nobody is free.
 */
beforeEach(function () {
    config()->set('telephony.agent.endpoint', 'PJSIP/1003');
    config()->set('telephony.agent.directory', []);
    Queue::fake();
});

it('reserves a free agent and rings THAT agent\'s resolved endpoint (RD-2/RD-4)', function () {
    config()->set('telephony.agent.directory', [
        7 => ['extension' => '1004', 'endpoint' => 'PJSIP/1004', 'password' => 's'],
    ]);
    $router = fakeAgentRouter(7);   // the board hands back agent 7

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-leg');
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1004', 'agent', null)->andReturn('agent-leg');

    $switchboard = new Switchboard($telephony);
    (new CallToAgentFlow($telephony, $switchboard))->handle(stasisStart('caller-leg', []));

    expect($router->reserved)->toBe([3]);   // reserved for the call's own company (label 3)
});

it('ends cleanly and never rings when no agent is free (RD-5 all busy)', function () {
    fakeAgentRouter(null);   // nobody free

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('hangup')->once()->with('caller-leg');   // clean end, no blind ring
    $telephony->shouldNotReceive('answer');
    $telephony->shouldNotReceive('placeCall');

    $switchboard = new Switchboard($telephony);
    (new CallToAgentFlow($telephony, $switchboard))->handle(stasisStart('caller-leg', []));

    Queue::assertNothingPushed();
});

it('ends cleanly when the call carries no company label — the router is never asked (RD-1)', function () {
    $router = fakeAgentRouter(6);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('hangup')->once()->with('caller-leg');
    $telephony->shouldNotReceive('answer');
    $telephony->shouldNotReceive('placeCall');

    $switchboard = new Switchboard($telephony);
    // tenantId: null → an unlabelled inbound call (no front-door company sticker).
    (new CallToAgentFlow($telephony, $switchboard))->handle(stasisStart('caller-leg', [], null, null));

    expect($router->reserved)->toBe([]);   // unlabelled → never read any company's board
});

it('releases the reservation when the agent rings out (Fold B — agent no-answer)', function () {
    $router = fakeAgentRouter(6);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-leg');
    $telephony->shouldReceive('placeCall')->once()->andReturn('agent-leg');
    $telephony->shouldReceive('hangup')->once()->with('caller-leg');   // no-answer tears the caller down

    $switchboard = new Switchboard($telephony);
    $flow = new CallToAgentFlow($telephony, $switchboard);
    $flow->handle(stasisStart('caller-leg', []));
    $flow->handle(channelDestroyed('agent-leg'));   // Asterisk's originate timeout → agent never answered

    expect($router->released)->toBe([[3, 6]]);   // the reservation is released, not leaked
});

it('releases the reservation when the caller abandons mid-ring (Fold B — the second no-answer path)', function () {
    $router = fakeAgentRouter(6);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-leg');
    $telephony->shouldReceive('placeCall')->once()->andReturn('agent-leg');
    $telephony->shouldReceive('hangup')->once()->with('agent-leg');   // caller gone → cancel the agent ring

    $switchboard = new Switchboard($telephony);
    $flow = new CallToAgentFlow($telephony, $switchboard);
    $flow->handle(stasisStart('caller-leg', []));
    $flow->handle(channelDestroyed('caller-leg'));   // caller hangs up WHILE the agent is ringing

    expect($router->released)->toBe([[3, 6]]);   // released on the caller-abandon path too
});

it('does NOT release once the agent answers — the screen takes over the status (RD-4)', function () {
    $router = fakeAgentRouter(6);
    $session = new RecordingSession('caller-leg', 'call-1', 'said', 'heard');

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-leg');
    $telephony->shouldReceive('placeCall')->once()->andReturn('agent-leg');
    $telephony->shouldReceive('join')->once()->andReturn('conv-1');
    $telephony->shouldReceive('startRecording')->once()->andReturn($session);
    $telephony->shouldReceive('stopRecording')->once();
    $telephony->shouldReceive('hangup')->once()->with('agent-leg');
    $telephony->shouldReceive('endConversation')->once();

    $switchboard = new Switchboard($telephony);
    $flow = new CallToAgentFlow($telephony, $switchboard);
    $flow->handle(stasisStart('caller-leg', []));
    $flow->handle(stasisStart('agent-leg', ['agent']));   // agent answers → connected
    $flow->handle(channelDestroyed('caller-leg'));        // normal hang-up after a real call

    expect($router->released)->toBe([]);   // the watcher never released — the agent genuinely connected
});
