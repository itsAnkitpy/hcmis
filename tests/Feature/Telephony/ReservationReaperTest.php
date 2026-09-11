<?php

use App\Enums\CampaignCategory;
use App\Enums\DialMode;
use App\Enums\LeadStatus;
use App\Enums\PresenceStatus;
use App\Enums\RoleName;
use App\Enums\TenantStatus;
use App\Models\AgentPresence;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use App\Telephony\AgentRouter;
use App\Telephony\Flows\Switchboard;
use App\Telephony\ProgressiveDialer;
use App\Telephony\ReservationReaper;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * DIAL-1 R8 — the reaper that puts back a desk tagged "On a call" with no call behind it.
 *
 * A desk is booked by writing OnCall to the database and handed back by a live handler.
 * Kill the listener between the two and the handler dies while the tag lives on, and
 * nothing else ever clears it: the stale-heartbeat net frees an agent whose browser
 * stopped stamping, and this one is sitting at their desk stamping every fifteen seconds.
 * They are gone from the floor until somebody edits the database.
 *
 * 🔴 The danger in the fix is the fix itself. Asterisk does NOT hang up channels when the
 * ARI connection drops — it deactivates the application and reactivates it on reconnect —
 * so a restarted listener routinely holds no handler for an agent who is genuinely
 * mid-conversation. Reaping on "no handler" alone hands a talking agent to the dialer.
 */
uses(RefreshDatabase::class);

afterEach(fn () => TenantContext::forget());

// Inside the client's own calling window, so a campaign is dialable whatever time it is
// here. Without this the dialer test below passes all afternoon and fails after 21:00 IST,
// which is when the default window closes.
beforeEach(fn () => test()->travelTo(Carbon::parse('2026-09-10 12:00:00', 'Asia/Kolkata')));

function bookedDesk(Tenant $tenant, PresenceStatus $status = PresenceStatus::OnCall): User
{
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $agent->forceFill(['sip_extension' => (string) (1100 + $agent->id)])->save();

    TenantContext::run($tenant->id, fn () => AgentPresence::factory()
        ->forUser($agent)
        ->status($status)
        ->create());

    return $agent;
}

/**
 * This client's board status for one agent. Its own, rather than AgentRouterTest's
 * statusOf(): a global declared in another test file only exists when that file is
 * loaded, so borrowing one makes this file pass in a full run and die in a filtered one.
 */
function reapedStatusOf(Tenant $tenant, User $agent): PresenceStatus
{
    return TenantContext::run($tenant->id, fn (): PresenceStatus => AgentPresence::query()
        ->where('user_id', $agent->id)->first()->status);
}

/** The state a listener wakes up in after a restart: no handlers, tags still in the database. */
function reapWith(mixed $telephony, ?Switchboard $switchboard = null): void
{
    (new ReservationReaper($telephony))->tick($switchboard ?? new Switchboard($telephony));
}

it('puts back a desk that no live call is holding', function () {
    $tenant = Tenant::factory()->create();
    $agent = bookedDesk($tenant);

    $telephony = fakeTelephony();
    // Their phone is idle — the call they were booked for died with the old listener.
    $telephony->shouldReceive('liveChannelNames')->once()->andReturn([]);

    reapWith($telephony);

    expect(reapedStatusOf($tenant, $agent))->toBe(PresenceStatus::Ready);
});

/**
 * 🔴 The one that makes the reaper safe to run at all. This agent is talking to a real
 * person right now; the listener simply restarted underneath them and holds no handler.
 * Freeing them here would let the dialer ring a desk that is mid-conversation.
 */
it('leaves a desk alone when the agent is still on a live channel', function () {
    $tenant = Tenant::factory()->create();
    $agent = bookedDesk($tenant);

    $telephony = fakeTelephony();
    $telephony->shouldReceive('liveChannelNames')->once()
        ->andReturn(['PJSIP/'.$agent->fresh()->sip_extension.'-0000a3c1']);

    reapWith($telephony);

    expect(reapedStatusOf($tenant, $agent))->toBe(PresenceStatus::OnCall);
});

/**
 * The desk the dialer booked one moment ago, before any leg exists to carry it — the
 * pendingReserved gap S88 added. It is held by a live handler, so it must survive, and
 * the voice box must not even be asked: on a healthy box every tag is matched by a
 * handler, no candidate exists, and the pass makes no HTTP call at all.
 */
it('leaves a desk the dialer has just booked, and asks the voice box nothing', function () {
    $tenant = Tenant::factory()->create();
    $agent = bookedDesk($tenant, PresenceStatus::Ready);

    $campaign = TenantContext::run($tenant->id, fn (): Campaign => Campaign::factory()->create([
        'dial_mode' => DialMode::Progressive,
        'is_dialing' => true,
        'category' => CampaignCategory::Promotional,
        'caller_id' => '+911400000000',
    ]));
    TenantContext::run($tenant->id, fn (): Lead => Lead::factory()
        ->forCampaign($campaign)->status(LeadStatus::New)
        ->create(['phone' => '9991234567', 'attempts' => 0]));

    $telephony = fakeTelephony();
    $telephony->shouldReceive('placeCall')->once()->andReturn('customer-leg');
    // 🔴 Never asked: no candidate, no question.
    $telephony->shouldNotReceive('liveChannelNames');

    $switchboard = new Switchboard($telephony);
    (new ProgressiveDialer($telephony, new AgentRouter))->tick($switchboard);

    expect(reapedStatusOf($tenant, $agent))->toBe(PresenceStatus::OnCall);

    reapWith($telephony, $switchboard);

    expect(reapedStatusOf($tenant, $agent))->toBe(PresenceStatus::OnCall);
});

/**
 * The agent's own screen stays the authority on Break and Wrap-up (the guard
 * releaseReservation already uses). Only an On a call tag is ever touched.
 */
it('never touches a status the agent set themselves', function () {
    $tenant = Tenant::factory()->create();
    $onBreak = bookedDesk($tenant, PresenceStatus::OnBreak);
    $wrapping = bookedDesk($tenant, PresenceStatus::WrappingUp);

    $telephony = fakeTelephony();
    $telephony->shouldNotReceive('liveChannelNames');

    reapWith($telephony);

    expect(reapedStatusOf($tenant, $onBreak))->toBe(PresenceStatus::OnBreak)
        ->and(reapedStatusOf($tenant, $wrapping))->toBe(PresenceStatus::WrappingUp);
});

/**
 * A suspended client should have nothing running at all, and quietly re-Ready-ing their
 * agents would be this code inventing a decision that belongs to whoever suspended them.
 */
it('leaves a suspended client alone entirely', function () {
    $tenant = Tenant::factory()->create(['status' => TenantStatus::Suspended]);
    $agent = bookedDesk($tenant);

    $telephony = fakeTelephony();
    $telephony->shouldNotReceive('liveChannelNames');

    reapWith($telephony);

    expect(reapedStatusOf($tenant, $agent))->toBe(PresenceStatus::OnCall);
});

/**
 * One client's stranded desk must not be readable from — or repairable by — a pass that
 * also sees another's. The read is ownerless by necessity (no client is in scope on the
 * loop), so this pins that each write lands back inside its own client's wall.
 */
it('frees each client its own desks and nobody elses', function () {
    $first = Tenant::factory()->create();
    $second = Tenant::factory()->create();
    $stranded = bookedDesk($first);
    $talking = bookedDesk($second);

    $telephony = fakeTelephony();
    $telephony->shouldReceive('liveChannelNames')->once()
        ->andReturn(['PJSIP/'.$talking->fresh()->sip_extension.'-0000b7']);

    reapWith($telephony);

    expect(reapedStatusOf($first, $stranded))->toBe(PresenceStatus::Ready)
        ->and(reapedStatusOf($second, $talking))->toBe(PresenceStatus::OnCall);
});
