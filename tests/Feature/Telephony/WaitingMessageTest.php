<?php

use App\Enums\CampaignCategory;
use App\Enums\DialMode;
use App\Enums\LeadStatus;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\Tenant;
use App\Telephony\Flows\CallFlowState;
use App\Telephony\Flows\CallToAgentFlow;
use App\Telephony\Flows\Switchboard;
use App\Telephony\TelephonyException;
use App\Telephony\TelephonyProvider;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

/**
 * inbound-audio.md slice 5 (AU-15) — the waiting announcement.
 *
 * Every sixty seconds of waiting the music pauses, the client's own "thanks for waiting"
 * plays, and the music comes back. It rides the waiting-area sweep the listener already
 * runs once a second: no scheduler, no timer class.
 *
 * DB-backed like the closed-hours tests, because the client row is what carries the
 * announcement. The flow tests with no client row behind the label hear nothing, by
 * design.
 *
 * The three decisions these prove (Ankit, S168):
 *   Q1  one clock, from arrival, never paused by a ringing desk
 *   Q2  no "we sent the stop" flag — the call's own state already says it
 *   Q3  the music note is cleared when the announcement starts, or the restart is a no-op
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    fakeNumberDirectory();
    fakeAgentDirectory();
    Queue::fake();
});

afterEach(fn () => TenantContext::forget());

/** A client with an announcement uploaded, and no music of their own (so: stock music). */
function clientWithWaitingMessage(): Tenant
{
    return Tenant::factory()->create([
        'waiting_message_path' => 'waiting-message/1/'.str_repeat('b', 64).'.wav',
    ]);
}

/** An inbound caller who lands in the waiting area because nobody is free. */
function holdingCallerFor(Tenant $tenant, TelephonyProvider $telephony): CallToAgentFlow
{
    $flow = new CallToAgentFlow($telephony, new Switchboard($telephony));
    $flow->handle(stasisStart('caller-leg', [], '9998887777', (string) $tenant->id));

    return $flow;
}

it('says nothing for the first minute, then plays the client\'s announcement (AU-15)', function () {
    $tenant = clientWithWaitingMessage();
    fakeAgentRouter(null);   // nobody free, and nobody frees up

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-leg');
    $telephony->shouldReceive('startHoldMusic')->once()->with('caller-leg', null);

    $flow = holdingCallerFor($tenant, $telephony);

    // Sweeps through the first minute ask for nothing at all. A strict mock, so a play
    // here would fail the test outright.
    $flow->tryAgain();
    $this->travel(59)->seconds();
    $flow->tryAgain();

    // 🔴 The address is the client's own signed one, not a guess. S167 shipped a play
    // whose media value Asterisk silently skipped, so this asserts the value the rest of
    // the system builds rather than one invented here.
    $telephony->shouldReceive('play')->once()
        ->with('caller-leg', [$tenant->waitingMessageUrl()])
        ->andReturn('play-1');

    $this->travel(2)->seconds();
    $flow->tryAgain();
});

it('turns the music back on when the announcement ends, and plays again a minute later (R4)', function () {
    $tenant = clientWithWaitingMessage();
    fakeAgentRouter(null);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('play')->twice()->andReturn('play-1', 'play-2');

    // 🔴 THREE times: the arrival, and once after each announcement. Handing a file to a
    // line switches the music off and nothing turns it back on but us — and our own "the
    // music is on" note has to be cleared when the announcement starts, or this restart
    // is skipped as a no-op and the caller holds the rest of their wait in silence.
    $telephony->shouldReceive('startHoldMusic')->times(3)->with('caller-leg', null);

    $flow = holdingCallerFor($tenant, $telephony);

    $this->travel(61)->seconds();
    $flow->tryAgain();                                                  // first announcement
    $flow->handle(playbackFinished('play-1', 'caller-leg'));            // it runs out: music back

    // Still inside the same minute, so nothing new is asked for.
    $this->travel(30)->seconds();
    $flow->tryAgain();

    $this->travel(31)->seconds();
    $flow->tryAgain();                                                  // second announcement
    $flow->handle(playbackFinished('play-2', 'caller-leg'));
});

it('never starts a second announcement over one that is still playing', function () {
    // Nothing caps how long a client's file is, so an announcement CAN outlast the minute
    // between them. Without this guard the next sweep starts a second copy over the first
    // and the caller hears two voices — and the play id we hold would be the wrong one.
    $tenant = clientWithWaitingMessage();
    fakeAgentRouter(null);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('startHoldMusic')->once();
    $telephony->shouldReceive('play')->once()->andReturn('play-1');

    $flow = holdingCallerFor($tenant, $telephony);

    $this->travel(61)->seconds();
    $flow->tryAgain();

    // A minute later it is still running — no "it finished" event has arrived.
    $this->travel(61)->seconds();
    $flow->tryAgain();
    $flow->tryAgain();
});

it('ignores a sound that is not the one we started (slice 4\'s id match, reused)', function () {
    // The engine says WHICH play ended, never why, and slices 6 and 8 will each play
    // sounds of their own on this line. Acting on a foreign id would clear the
    // announcement we are still holding — so the next minute would start a second one
    // over the top of it, and the music would be restarted underneath a playing sound.
    $tenant = clientWithWaitingMessage();
    fakeAgentRouter(null);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('startHoldMusic')->once();   // the arrival's, and no other
    $telephony->shouldReceive('play')->once()->andReturn('play-1');

    $flow = holdingCallerFor($tenant, $telephony);
    $this->travel(61)->seconds();
    $flow->tryAgain();

    $flow->handle(playbackFinished('some-other-sound', 'caller-leg'));

    $this->travel(61)->seconds();
    $flow->tryAgain();
});

it('stops the announcement, brings the music back, then rings the desk that freed up', function () {
    $tenant = clientWithWaitingMessage();
    $router = fakeAgentRouter(null);

    $ordered = [];
    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('startHoldMusic')->with('caller-leg', null)
        ->andReturnUsing(function () use (&$ordered): void {
            $ordered[] = 'music on';
        });
    $telephony->shouldReceive('play')->once()->andReturnUsing(function () use (&$ordered): string {
        $ordered[] = 'announcement';

        return 'play-1';
    });

    $flow = holdingCallerFor($tenant, $telephony);
    $this->travel(61)->seconds();
    $flow->tryAgain();

    // A desk frees up mid-announcement. The caller is never made to finish listening, and
    // the ORDER is the point: stop the announcement, hand the music back, then ring —
    // because a caller hearing silence through a twenty-second ring reads it as a dropped
    // call (S88 review #3).
    $router->agentId = 6;
    $telephony->shouldReceive('stopPlayback')->once()->with('play-1')
        ->andReturnUsing(function () use (&$ordered): void {
            $ordered[] = 'announcement off';
        });
    $telephony->shouldReceive('placeCall')->once()->andReturnUsing(function () use (&$ordered): string {
        $ordered[] = 'ring';

        return 'agent-leg';
    });

    $flow->tryAgain();

    expect($ordered)->toBe(['music on', 'announcement', 'announcement off', 'music on', 'ring']);
});

it('keeps the caller when stopping an announcement that has just ended is refused', function () {
    // 🔴 The race this exists for: an announcement runs a few seconds and the sweep runs
    // every second, so a desk freeing up as the announcement ends is ordinary. Asterisk
    // answers "not found" for a sound that no longer exists and our phone layer turns
    // every refusal into an exception — which the sweep answers by tearing the call down.
    // The caller would be hung up on because their message finished on time.
    $tenant = clientWithWaitingMessage();
    $router = fakeAgentRouter(null);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('startHoldMusic')->twice();
    $telephony->shouldReceive('play')->once()->andReturn('play-1');

    $flow = holdingCallerFor($tenant, $telephony);
    $this->travel(61)->seconds();
    $flow->tryAgain();

    $router->agentId = 6;
    $telephony->shouldReceive('stopPlayback')->once()
        ->andThrow(new TelephonyException('Asterisk refused DELETE /playbacks/play-1 — HTTP 404'));
    $telephony->shouldReceive('placeCall')->once()->andReturn('agent-leg');

    $flow->tryAgain();

    // The caller is still on the line and their desk is ringing: no hangup, no teardown.
    expect($flow->state())->toBe(CallFlowState::RingingAgent);
});

it('keeps the caller waiting when the announcement itself cannot be started', function () {
    // The announcement is optional; the wait is not. A refused play must not end the call,
    // and it must not retry every second either — the clock is stamped either way.
    $tenant = clientWithWaitingMessage();
    fakeAgentRouter(null);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('startHoldMusic')->once();
    $telephony->shouldReceive('play')->once()->andThrow(new TelephonyException('Asterisk refused POST'));

    $flow = holdingCallerFor($tenant, $telephony);
    $this->travel(61)->seconds();
    $flow->tryAgain();

    $this->travel(2)->seconds();
    $flow->tryAgain();   // no second attempt inside the same minute

    expect($flow->state())->toBe(CallFlowState::Waiting);
});

it('counts the minute from arrival, so a caller handed back by a silent desk hears it at once (Q1)', function () {
    $tenant = clientWithWaitingMessage();
    $router = fakeAgentRouter(6);   // somebody IS free when the call arrives

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    // Once only: the music starts with the first ring and never stops, so being put back
    // in the waiting area finds it already playing.
    $telephony->shouldReceive('startHoldMusic')->once()->with('caller-leg', null);
    $telephony->shouldReceive('placeCall')->once()->andReturn('agent-leg');

    $flow = holdingCallerFor($tenant, $telephony);   // rings a desk straight away

    // That desk rings for sixty-five seconds and nobody picks up. From the caller's side
    // they have been waiting the whole time, so the clock does not restart when they are
    // put back — the announcement is simply due the moment they return.
    $this->travel(65)->seconds();
    $telephony->shouldReceive('play')->once()
        ->with('caller-leg', [$tenant->waitingMessageUrl()])
        ->andReturn('play-1');

    $flow->handle(channelDestroyed('agent-leg'));   // back to the waiting area
    $flow->tryAgain();

    expect($router->agentId)->toBe(6);
});

it('never plays the announcement to a customer the dialer rang (AU-15, S163)', function () {
    // The waiting area holds both directions. "Thanks for waiting" to somebody WE rang is
    // nonsense, and the marker is one this call already carries: only the dialer sets a
    // lead id. A strict mock, so any play at all fails this test.
    $tenant = clientWithWaitingMessage();
    fakeAgentRouter(null);

    [$campaign, $lead] = TenantContext::run($tenant->id, function (): array {
        $campaign = Campaign::factory()->create([
            'dial_mode' => DialMode::Progressive,
            'is_dialing' => true,
            'category' => CampaignCategory::Promotional,
        ]);

        return [$campaign, Lead::factory()->forCampaign($campaign)->status(LeadStatus::New)->create([
            'phone' => '9991234567',
        ])];
    });

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('placeCall')->once()->andReturn('customer-leg');
    $telephony->shouldReceive('placeCall')->once()->andReturn('agent-leg');
    $telephony->shouldReceive('startHoldMusic')->once();

    $flow = new CallToAgentFlow($telephony, new Switchboard($telephony));
    $flow->beginDialedCall($tenant->id, 6, $lead, $campaign);
    $flow->handle(stasisStart('customer-leg', ['dialer']));   // they answer; the desk rings
    $flow->handle(channelDestroyed('agent-leg'));             // the desk rings out

    $this->travel(120)->seconds();
    $flow->tryAgain();

    expect($flow->state())->toBe(CallFlowState::Waiting);
});

it('plays nothing for a client who has uploaded no announcement', function () {
    $tenant = Tenant::factory()->create();   // no waiting message
    fakeAgentRouter(null);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('startHoldMusic')->once();

    $flow = holdingCallerFor($tenant, $telephony);

    $this->travel(120)->seconds();
    $flow->tryAgain();

    expect($flow->state())->toBe(CallFlowState::Waiting);
});
