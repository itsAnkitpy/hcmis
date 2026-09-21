<?php

use App\Enums\CallDirection;
use App\Enums\CallEndedBy;
use App\Enums\CallOutcome;
use App\Enums\ClosedHours;
use App\Enums\MissedReason;
use App\Models\Call;
use App\Models\Tenant;
use App\Telephony\Flows\CallToAgentFlow;
use App\Telephony\Flows\Switchboard;
use App\Telephony\TelephonyProvider;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

/**
 * inbound-audio.md slice 1 — the door reads the office hours sign before it picks up.
 *
 * Closed, with the switch on: the call is never answered. It is ended with the "busy"
 * reason (AU-2, S163: no reason sends "declined", which networks play as "not in
 * service") and lands on Missed Calls marked "called while closed" (AU-3). Open, or the
 * switch off: today's path, unchanged.
 *
 * DB-backed like MissedCallRecordTest, because the client row is what the door reads.
 * The call tests with no client row behind the label stay open by design.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    fakeNumberDirectory();
    fakeAgentDirectory();
    Queue::fake();
});

afterEach(function () {
    TenantContext::forget();
});

/** A client whose every weekday is set to $day (null = closed all week). */
function clientWithWeek(?array $day, ClosedHours $closedHours): Tenant
{
    $week = array_fill_keys(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], $day);

    return Tenant::factory()->create([
        'settings' => ['hours' => $week, 'closed_hours' => $closedHours->value],
    ]);
}

it('does not pick up a call while the client is closed, and ends it as busy (AU-2)', function () {
    $tenant = clientWithWeek(null, ClosedHours::NoPickup);
    fakeAgentRouter(6);   // a Ready agent must not be rung either (AU-5)

    // A strict mock: answering, ringing or playing music would each fail the test.
    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('hangup')->once()->with('caller-leg', 'busy');

    $flow = new CallToAgentFlow($telephony, new Switchboard($telephony));
    $flow->handle(stasisStart('caller-leg', [], '9998887777', (string) $tenant->id));

    $call = TenantContext::cross(fn () => Call::query()->sole());

    expect($call->tenant_id)->toBe($tenant->id)
        ->and($call->direction)->toBe(CallDirection::Inbound)
        ->and($call->outcome)->toBe(CallOutcome::NoAnswer)          // no new outcome value (slice 1)
        ->and($call->ended_by)->toBe(CallEndedBy::System)           // we ended it
        ->and($call->missed_reason)->toBe(MissedReason::ClosedHours)
        ->and($call->from_number)->toBe('9998887777')
        ->and($call->agent_id)->toBeNull();
});

it('picks up as today while the client is open', function () {
    $tenant = clientWithWeek(['open' => '00:00', 'close' => '00:00'], ClosedHours::NoPickup);   // 24 hours
    fakeAgentRouter(null);   // nobody free — the caller waits, as today

    $telephony = fakeTelephony();
    $telephony->shouldReceive('answer')->once()->with('caller-leg');

    $flow = new CallToAgentFlow($telephony, new Switchboard($telephony));
    $flow->handle(stasisStart('caller-leg', [], '9998887777', (string) $tenant->id));

    expect(TenantContext::cross(fn () => Call::query()->count()))->toBe(0);
});

it('picks up as today when the switch is off, whatever the hours say (AU-1)', function () {
    $tenant = clientWithWeek(null, ClosedHours::Off);
    fakeAgentRouter(null);

    $telephony = fakeTelephony();
    $telephony->shouldReceive('answer')->once()->with('caller-leg');

    $flow = new CallToAgentFlow($telephony, new Switchboard($telephony));
    $flow->handle(stasisStart('caller-leg', [], '9998887777', (string) $tenant->id));

    expect(TenantContext::cross(fn () => Call::query()->count()))->toBe(0);
});

// --- slice 4: the third choice — answer, say why, hang up ---

/** A client that is closed all week and has a closed message ready to play. */
function clientClosedWithMessage(): Tenant
{
    $tenant = clientWithWeek(null, ClosedHours::Message);
    $tenant->update(['closed_message_path' => 'closed-message/'.$tenant->id.'/'.str_repeat('a', 64).'.wav']);

    return $tenant->fresh();
}

it('answers, plays the client\'s closed message, and files the caller straight away (AU-3)', function () {
    $tenant = clientClosedWithMessage();
    fakeAgentRouter(6);   // a Ready agent must still not be rung (AU-5)

    // A strict mock: ringing an agent or starting hold music would each fail the test.
    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-leg');
    $telephony->shouldReceive('play')->once()
        ->with('caller-leg', [$tenant->closedMessageUrl()])
        ->andReturn('playback-1');

    $flow = new CallToAgentFlow($telephony, new Switchboard($telephony));
    $flow->handle(stasisStart('caller-leg', [], '9998887777', (string) $tenant->id));

    // 🔴 The row exists BEFORE the message has finished. That is the point: no ending
    // can lose it.
    $call = TenantContext::cross(fn () => Call::query()->sole());

    expect($call->missed_reason)->toBe(MissedReason::ClosedHours)
        ->and($call->outcome)->toBe(CallOutcome::NoAnswer)
        ->and($call->direction)->toBe(CallDirection::Inbound)
        ->and($call->from_number)->toBe('9998887777')
        ->and($call->agent_id)->toBeNull();
});

it('ends the call once the closed message has finished', function () {
    $tenant = clientClosedWithMessage();
    fakeAgentRouter(null);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('play')->once()->andReturn('playback-1');
    $telephony->shouldReceive('hangup')->once()->with('caller-leg');

    $switchboard = new Switchboard($telephony);
    $switchboard->handle(stasisStart('caller-leg', [], '9998887777', (string) $tenant->id));
    $switchboard->handle(playbackFinished('playback-1', 'caller-leg'));

    expect($switchboard->activeCallCount())->toBe(0)
        ->and(TenantContext::cross(fn () => Call::query()->count()))->toBe(1);
});

it('still files a caller who hangs up part-way through the message', function () {
    // The "Done when" line this slice exists for. The row is written at the door, so a
    // caller who gives up mid-sentence is on Missed Calls exactly like one who listened.
    $tenant = clientClosedWithMessage();
    fakeAgentRouter(null);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('play')->once()->andReturn('playback-1');
    // No hangup expected: the caller did it themselves.

    $switchboard = new Switchboard($telephony);
    $switchboard->handle(stasisStart('caller-leg', [], '9998887777', (string) $tenant->id));
    $switchboard->handle(stasisEnd('caller-leg'));

    $call = TenantContext::cross(fn () => Call::query()->sole());

    expect($call->missed_reason)->toBe(MissedReason::ClosedHours)
        ->and($switchboard->activeCallCount())->toBe(0);
});

it('writes exactly one Missed Calls row, however the message ends', function () {
    // Both endings arrive on a real call: we hang up when the sound finishes, and the
    // leg's own ending follows. Neither may write a second row.
    $tenant = clientClosedWithMessage();
    fakeAgentRouter(null);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('play')->once()->andReturn('playback-1');
    $telephony->shouldReceive('hangup')->once();

    $switchboard = new Switchboard($telephony);
    $switchboard->handle(stasisStart('caller-leg', [], '9998887777', (string) $tenant->id));
    $switchboard->handle(playbackFinished('playback-1', 'caller-leg'));
    $switchboard->handle(channelDestroyed('caller-leg'));

    expect(TenantContext::cross(fn () => Call::query()->count()))->toBe(1);
});

it('ignores a sound finishing that is not the closed message it started', function () {
    // The engine says which PLAY ended, never why. Slices 5 and 6 play their own sounds
    // on the same line, so an unmatched id must not end anybody's call.
    $tenant = clientClosedWithMessage();
    fakeAgentRouter(null);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('play')->once()->andReturn('playback-1');
    // No hangup expected.

    $switchboard = new Switchboard($telephony);
    $switchboard->handle(stasisStart('caller-leg', [], '9998887777', (string) $tenant->id));
    $switchboard->handle(playbackFinished('some-other-playback', 'caller-leg'));

    expect($switchboard->activeCallCount())->toBe(1);
});

it('falls back to not picking up when the choice is set but the file has gone', function () {
    // The edit form refuses this combination, but a caller must never be answered into
    // silence and then hung up on — that is strictly worse than a busy tone.
    $tenant = clientWithWeek(null, ClosedHours::Message);
    fakeAgentRouter(6);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('hangup')->once()->with('caller-leg', 'busy');

    $flow = new CallToAgentFlow($telephony, new Switchboard($telephony));
    $flow->handle(stasisStart('caller-leg', [], '9998887777', (string) $tenant->id));

    expect(TenantContext::cross(fn () => Call::query()->sole())->missed_reason)
        ->toBe(MissedReason::ClosedHours);
});

it('ends only the call whose message finished, leaving its neighbour alone', function () {
    // The end-to-end outcome with two calls live: only the one whose message finished
    // ends. Note what this does and does not prove — the flow's own two guards (its
    // state, then the playback id) are what make it true, and they hold even if the
    // switchboard hands the event to every call. The switchboard's narrowing to one
    // handler is a second layer, matching how every other event on that switch routes;
    // deliberately breaking it does NOT turn this red. What DOES turn it red is the
    // event not being routed at all, which is how it behaved before this slice.
    $closed = clientClosedWithMessage();
    $open = clientWithWeek(['open' => '00:00', 'close' => '00:00'], ClosedHours::Off);
    fakeAgentRouter(null);   // the open client's caller finds nobody free and waits

    $telephony = fakeTelephony();
    $telephony->shouldReceive('answer')->twice();
    $telephony->shouldReceive('play')->once()->andReturn('playback-1');
    $telephony->shouldReceive('hangup')->once()->with('closed-caller');

    $switchboard = new Switchboard($telephony);
    $switchboard->handle(stasisStart('waiting-caller', [], '9998887777', (string) $open->id));
    $switchboard->handle(stasisStart('closed-caller', [], '9996665555', (string) $closed->id));

    $switchboard->handle(playbackFinished('playback-1', 'closed-caller'));

    // The closed call is gone; the waiting one is untouched and still holding.
    expect($switchboard->activeCallCount())->toBe(1)
        ->and(TenantContext::cross(fn () => Call::query()->pluck('tenant_id')->all()))->toBe([$closed->id]);
});
