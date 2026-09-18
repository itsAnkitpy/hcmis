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
