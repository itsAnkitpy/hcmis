<?php

use App\Telephony\Flows\Switchboard;
use App\Telephony\LiveCallCounts;
use App\Telephony\RecordingSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

/**
 * Call Stats CS-1/CS-2/CS-3 — the three numbers a team leader watches: how many calls
 * are connected, how many are ringing, and how many callers are holding.
 *
 * Two halves, tested separately because they are two programs:
 *  - the TALLY, which sorts the switchboard's live calls into those three numbers. Driven
 *    with the same raw event shapes the listener reads off the phone engine (the helpers
 *    in tests/Pest.php), so nothing here needs a phone line;
 *  - the PIGEONHOLE, the little note the listener leaves and the website picks up. Tests
 *    run on the array cache, which honours Laravel's fake clock, so the note's twenty
 *    seconds can be tested by moving time rather than by waiting.
 */
beforeEach(function () {
    config()->set('telephony.agent.endpoint', 'PJSIP/1003');
    config()->set('telephony.agent.directory', []);

    fakeNumberDirectory();
    Queue::fake();
});

it('sorts every live call into the three numbers, kept apart by client', function () {
    $router = fakeAgentRouter(6);
    $session = new RecordingSession('caller-A', 'call-A', 'A-said', 'A-heard');

    $telephony = fakeTelephony();
    $telephony->shouldReceive('answer')->zeroOrMoreTimes();
    $telephony->shouldReceive('placeCall')->andReturn('agent-A', 'agent-B', 'customer-D');
    $telephony->shouldReceive('join')->once()->andReturn('conv-A');
    $telephony->shouldReceive('startRecording')->once()->andReturn($session);

    $switchboard = new Switchboard($telephony);

    // Client 3, call one: connected to an agent.
    $switchboard->handle(stasisStart('caller-A', [], tenantId: '3'));
    $switchboard->handle(stasisStart('agent-A', ['agent']));

    // Client 3, call two: an agent's phone is ringing.
    $switchboard->handle(stasisStart('caller-B', [], tenantId: '3'));

    // Client 3, call three: nobody was free by now, so this caller is holding.
    $router->agentId = null;
    $switchboard->handle(stasisStart('caller-C', [], tenantId: '3'));

    // Client 7: an outbound call with the customer's phone ringing. Its client rides in
    // on the label the console stamped (CS-4) — the fifth value.
    $switchboard->handle(stasisStart('agent-D', ['agent', '5550000', 'uuid-D', '6', '7']));

    expect($switchboard->tallyByTenant())->toBe([
        3 => ['active' => 1, 'ringing' => 1, 'waiting' => 1],
        7 => ['active' => 0, 'ringing' => 1, 'waiting' => 0],
    ]);
});

it('counts a call that is mid-transfer as active, so the number does not dip every time an agent transfers', function () {
    fakeAgentRouter(6);
    $session = new RecordingSession('caller-A', 'call-A', 'A-said', 'A-heard');

    $telephony = fakeTelephony();
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('placeCall')->andReturn('agent-A', 'agent-B');
    $telephony->shouldReceive('join')->once()->andReturn('conv-A');
    $telephony->shouldReceive('startRecording')->once()->andReturn($session);

    $switchboard = new Switchboard($telephony);
    $switchboard->handle(stasisStart('caller-A', [], tenantId: '3'));
    $switchboard->handle(stasisStart('agent-A', ['agent']));

    // The agent clicks Transfer: a second agent is being rung while the caller stays put.
    $switchboard->handle(channelUserevent('transfer', ['agentUserId' => '6', 'tenantId' => '3']));

    expect($switchboard->tallyByTenant())->toBe([
        3 => ['active' => 1, 'ringing' => 0, 'waiting' => 0],
    ]);
});

it('leaves a call with no client out of every count rather than putting it in a default one', function () {
    fakeAgentRouter(6);

    $telephony = fakeTelephony();
    $telephony->shouldReceive('placeCall')->once()->andReturn('customer-G');

    $switchboard = new Switchboard($telephony);

    // Our own global staff dial from no client, so the label carries no client — four
    // values, not five. The call runs normally; it is simply not counted for anybody.
    $switchboard->handle(stasisStart('agent-G', ['agent', '5550000', 'uuid-G', '6']));

    expect($switchboard->activeCallCount())->toBe(1)
        ->and($switchboard->tallyByTenant())->toBe([]);
});

it('adds up the notes of every client it is asked about, and ignores clients that have none', function () {
    $counts = new LiveCallCounts;

    $counts->publish([
        3 => ['active' => 2, 'ringing' => 1, 'waiting' => 0],
        7 => ['active' => 1, 'ringing' => 0, 'waiting' => 4],
    ]);

    // A team leader reads their own client only.
    expect($counts->read([3]))->toBe(['active' => 2, 'ringing' => 1, 'waiting' => 0]);

    // Our own global staff read every client, including one that has no note at all.
    expect($counts->read([3, 7, 9]))->toBe(['active' => 3, 'ringing' => 1, 'waiting' => 4]);
});

it('tells a client that has gone quiet, instead of leaving its last note showing live calls', function () {
    $counts = new LiveCallCounts;

    $counts->publish([3 => ['active' => 3, 'ringing' => 0, 'waiting' => 0]]);
    expect($counts->read([3]))->toBe(['active' => 3, 'ringing' => 0, 'waiting' => 0]);

    // That floor's last call ends, so the tally stops mentioning it entirely.
    $counts->publish([]);
    expect($counts->read([3]))->toBe(['active' => 0, 'ringing' => 0, 'waiting' => 0]);

    // And it settles: the zeros we wrote are not themselves remembered, so the next quiet
    // pass writes nothing at all and the note is left to expire.
    Cache::flush();
    $counts->publish([]);
    expect($counts->read([3]))->toBe(['active' => 0, 'ringing' => 0, 'waiting' => 0])
        ->and(Cache::has('telephony:live-calls:3'))->toBeFalse();
});

it('fades out on its own when the listener stops, instead of showing stale numbers all shift', function () {
    $counts = new LiveCallCounts;

    $counts->publish([3 => ['active' => 3, 'ringing' => 1, 'waiting' => 2]]);

    expect($counts->isReporting())->toBeTrue();

    // The listener dies. Nothing cleans up — the notes simply run out.
    $this->travel(LiveCallCounts::LIFETIME_SECONDS + 1)->seconds();

    expect($counts->isReporting())->toBeFalse()
        ->and($counts->read([3]))->toBe(['active' => 0, 'ringing' => 0, 'waiting' => 0]);
});

it('says the phone service is reporting even when not one call is in flight anywhere', function () {
    $counts = new LiveCallCounts;

    $counts->publish([]);

    // The whole point of the aliveness note: a calm floor and a dead listener must not
    // look the same, and both of them show zeros.
    expect($counts->isReporting())->toBeTrue()
        ->and($counts->read([3]))->toBe(['active' => 0, 'ringing' => 0, 'waiting' => 0]);
});
