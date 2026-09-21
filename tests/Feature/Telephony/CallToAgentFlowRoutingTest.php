<?php

use App\Telephony\AgentDirectory;
use App\Telephony\AgentPhoneWriter;
use App\Telephony\Flows\CallToAgentFlow;
use App\Telephony\Flows\Switchboard;
use App\Telephony\RecordingSession;
use App\Telephony\TelephonyProvider;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => fakeAgentDirectory());   // SEC-1 slice 4: the reserved agent has a phone

/**
 * B2.2b — the inbound flow picks a free agent and rings THEM (RD-1..RD-5 + Fold B).
 * The board read + atomic reserve/release are proven against the DB in AgentRouterTest;
 * here we prove the FLOW wiring against a mocked provider + a stub router: it reads the
 * company label off the call, reserves BEFORE ringing, rings the RESOLVED endpoint,
 * releases on both no-answer paths, and ends cleanly when nobody is free.
 */
beforeEach(function () {
    fakeNumberDirectory();   // resolves the call's dialled number to its company (B2.3a)
    Queue::fake();
});

/**
 * 🔴 The one test here that runs the REAL directory (SEC-1 slice 4): its whole claim
 * is that the endpoint comes from the RESERVED agent's own record, so a stub handing
 * back one endpoint for everyone would prove nothing. Every other test in this file
 * stubs it, because they are about call mechanics rather than whose phone rings.
 */
it('reserves a free agent and rings THAT agent\'s resolved endpoint (RD-2/RD-4)', function () {
    // A directory that answers with the agent id it was ASKED about, so the assertion
    // below can only pass if the flow resolved the endpoint for the RESERVED agent.
    // (Which record the real directory reads is AgentDirectoryTest's claim, not this
    // file's — these tests carry no database.)
    app()->instance(AgentDirectory::class, new class(app(AgentPhoneWriter::class)) extends AgentDirectory
    {
        public function endpointFor(int $userId): ?string
        {
            return 'PJSIP/'.$userId;
        }
    });
    $router = fakeAgentRouter(1004);   // the board hands back agent 1004

    $telephony = fakeTelephony();
    $telephony->shouldReceive('answer')->once()->with('caller-leg');
    $telephony->shouldReceive('placeCall')->once()->with('PJSIP/1004', 'agent', null, 20)->andReturn('agent-leg');

    $switchboard = new Switchboard($telephony);
    (new CallToAgentFlow($telephony, $switchboard))->handle(stasisStart('caller-leg', []));

    expect($router->reserved)->toBe([3]);   // reserved for the call's own company (label 3)
});

// B2.3b-i QD-4, the FIRST door: RD-5's clean end is no longer an end. The caller is
// answered and held with music instead of being cut off — and still never blind-rung.
it('answers the caller and starts hold music when no agent is free (QD-4 — was the all-busy hang-up)', function () {
    fakeAgentRouter(null);   // nobody free

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-leg');          // answered, or there is nothing to play into
    $telephony->shouldReceive('startHoldMusic')->once()->with('caller-leg', null);
    $telephony->shouldNotReceive('hangup');
    $telephony->shouldNotReceive('placeCall');                                // still no blind ring

    $switchboard = new Switchboard($telephony);
    $switchboard->handle(stasisStart('caller-leg', []));   // through the switchboard, so the call is booked in

    expect($switchboard->activeCallCount())->toBe(1);   // the call is held, not disposed
    Queue::assertNothingPushed();
});

it('ends cleanly when the dialled number belongs to no client — the router is never asked (ND-4)', function () {
    $router = fakeAgentRouter(6);

    $telephony = fakeTelephony();
    $telephony->shouldReceive('hangup')->once()->with('caller-leg');
    $telephony->shouldNotReceive('answer');
    $telephony->shouldNotReceive('placeCall');

    $switchboard = new Switchboard($telephony);
    // tenantId: null → a call on a number we do not know, or one switched off.
    (new CallToAgentFlow($telephony, $switchboard))->handle(stasisStart('caller-leg', [], null, null));

    expect($router->reserved)->toBe([]);   // no owner → never read any company's board
});

it('releases the reservation when the agent rings out (Fold B — agent no-answer)', function () {
    $router = fakeAgentRouter(6);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-leg');
    $telephony->shouldReceive('placeCall')->once()->andReturn('agent-leg');
    $telephony->shouldReceive('startHoldMusic')->once();   // the caller waits now (QD-4), never hung up on

    $switchboard = new Switchboard($telephony);
    $flow = new CallToAgentFlow($telephony, $switchboard);
    $flow->handle(stasisStart('caller-leg', []));
    $flow->handle(channelDestroyed('agent-leg'));   // Asterisk's originate timeout → agent never answered

    expect($router->released)->toBe([[3, 6]]);   // the reservation is released, not leaked
});

it('releases the reservation when the caller abandons mid-ring (Fold B — the second no-answer path)', function () {
    $router = fakeAgentRouter(6);

    $telephony = fakeTelephony();
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

    $telephony = fakeTelephony();
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
