<?php

use App\Enums\CallDirection;
use App\Enums\CallOutcome;
use App\Enums\CampaignCategory;
use App\Enums\DialMode;
use App\Enums\LeadStatus;
use App\Enums\PresenceStatus;
use App\Enums\RoleName;
use App\Enums\TenantStatus;
use App\Filament\Pages\AgentConsole;
use App\Models\ActivityLog;
use App\Models\AgentPresence;
use App\Models\Call;
use App\Models\CallHandoff;
use App\Models\Campaign;
use App\Models\DncEntry;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use App\Telephony\AgentRouter;
use App\Telephony\Flows\Switchboard;
use App\Telephony\ProgressiveDialer;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

afterEach(fn () => TenantContext::forget());

/**
 * DIAL-1 slice 3 (DP-7 … DP-10) — the tick that takes the caller off the agent's finger.
 *
 * 🔴 These prove the parts that CAN be proven without a phone: a desk is booked before a
 * number is dialled, and every path that gives up hands that desk straight back. The
 * dialing itself — an agent going Ready and their phone ringing with a live customer — is
 * the staging check, and the plan says so explicitly.
 *
 * The real AgentRouter is used deliberately, not the stub the switchboard tests use: the
 * booking and the handing back ARE the thing under test here.
 */
beforeEach(function () {
    // Inside the legal window on the client's clock, so a campaign is dialable by default.
    $this->travelTo(Carbon::parse('2026-09-10 12:00:00', 'Asia/Kolkata'));
});

function readyDeskOn(Tenant $tenant): User
{
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    // forceFill: sip_extension is deliberately not fillable (PP-1), and SEC-1 slice 4 made
    // a phone the price of being reservable.
    $agent->forceFill(['sip_extension' => (string) (1100 + $agent->id)])->save();

    TenantContext::run($tenant->id, fn () => AgentPresence::factory()
        ->forUser($agent)
        ->status(PresenceStatus::Ready)
        ->create());

    return $agent;
}

function dialingCampaignOn(Tenant $tenant, ?string $callerId = '+911400000000'): Campaign
{
    return TenantContext::run($tenant->id, fn (): Campaign => Campaign::factory()->create([
        'dial_mode' => DialMode::Progressive,
        'is_dialing' => true,
        'category' => CampaignCategory::Promotional,
        'caller_id' => $callerId,
    ]));
}

function leadOn(Tenant $tenant, Campaign $campaign, string $phone = '9991234567'): Lead
{
    return TenantContext::run($tenant->id, fn (): Lead => Lead::factory()
        ->forCampaign($campaign)
        ->status(LeadStatus::New)
        ->create(['phone' => $phone, 'attempts' => 0]));
}

function deskStatus(Tenant $tenant, User $agent): PresenceStatus
{
    return TenantContext::run($tenant->id, fn (): PresenceStatus => AgentPresence::query()
        ->where('user_id', $agent->id)->first()->status);
}

function tickWith(mixed $telephony): Switchboard
{
    $switchboard = new Switchboard($telephony);

    (new ProgressiveDialer($telephony, new AgentRouter))->tick($switchboard);

    return $switchboard;
}

it('books a desk, claims a lead and rings the number on the campaign caller ID', function () {
    $tenant = Tenant::factory()->create();
    $agent = readyDeskOn($tenant);
    $campaign = dialingCampaignOn($tenant);
    $lead = leadOn($tenant, $campaign);

    $telephony = fakeTelephony();
    // 🔴 The campaign's own number, not the system default: marketing must present a
    // 140-series number and support a 1601-series one (DQ-5).
    $telephony->shouldReceive('placeCall')->once()
        ->with('PJSIP/9991234567', 'dialer', '+911400000000')
        ->andReturn('customer-leg');

    tickWith($telephony);

    expect(deskStatus($tenant, $agent))->toBe(PresenceStatus::OnCall)
        ->and(TenantContext::run($tenant->id, fn () => $lead->fresh()->claimed_at))->not->toBeNull();
});

it('dials nothing when no desk is free', function () {
    $tenant = Tenant::factory()->create();
    $campaign = dialingCampaignOn($tenant);
    $lead = leadOn($tenant, $campaign);

    $telephony = fakeTelephony();
    $telephony->shouldNotReceive('placeCall');

    tickWith($telephony);

    // Nothing was booked, so nothing had to be handed back — and the lead is untouched.
    expect(TenantContext::run($tenant->id, fn () => $lead->fresh()->claimed_at))->toBeNull();
});

it('hands the desk straight back when the campaign has no lead left to call', function () {
    $tenant = Tenant::factory()->create();
    $agent = readyDeskOn($tenant);
    dialingCampaignOn($tenant);   // no leads on it

    $telephony = fakeTelephony();
    $telephony->shouldNotReceive('placeCall');

    tickWith($telephony);

    expect(deskStatus($tenant, $agent))->toBe(PresenceStatus::Ready);
});

it('never dials a do-not-call number: closes the lead, audits it, counts no attempt, frees the desk', function () {
    $tenant = Tenant::factory()->create();
    $agent = readyDeskOn($tenant);
    $campaign = dialingCampaignOn($tenant);
    $lead = leadOn($tenant, $campaign);
    TenantContext::run($tenant->id, fn () => DncEntry::factory()->create(['phone' => '9991234567']));

    $telephony = fakeTelephony();
    $telephony->shouldNotReceive('placeCall');

    tickWith($telephony);

    $fresh = TenantContext::run($tenant->id, fn () => $lead->fresh());

    expect($fresh->status)->toBe(LeadStatus::Closed)
        // 🔴 No call was placed, so no attempt is counted — faking one would lie to the
        // campaign reports (the console's own hard-stop, shared since A1).
        ->and($fresh->attempts)->toBe(0)
        ->and(deskStatus($tenant, $agent))->toBe(PresenceStatus::Ready);

    // On the `call` stream, against the lead, with NO person behind it — the dialer
    // blocked this one, not an agent.
    $blocked = TenantContext::run($tenant->id, fn (): ?ActivityLog => ActivityLog::query()
        ->where('log_name', 'call')->where('event', 'dnc_blocked')->latest('id')->first());

    expect($blocked)->not->toBeNull()
        ->and($blocked->subject_id)->toBe($lead->id)
        ->and($blocked->causer_id)->toBeNull();
});

/**
 * 🔴 The window is the CLIENT's wall clock, not the application's (F7). Without this the
 * tick could ask switchedOn() instead of dialable() and every other test here would stay
 * green — they all sit at noon. That one-word slip dials a floor at three in the morning.
 */
it('dials nothing outside the campaign calling window', function () {
    $tenant = Tenant::factory()->create();
    $agent = readyDeskOn($tenant);
    $campaign = dialingCampaignOn($tenant);
    leadOn($tenant, $campaign);

    // 03:00 in India, outside the campaign's 10:00-21:00 default — and 21:30 the previous
    // day on the UTC application clock, which is comfortably INSIDE it.
    $this->travelTo(Carbon::parse('2026-09-10 03:00:00', 'Asia/Kolkata'));

    $telephony = fakeTelephony();
    $telephony->shouldNotReceive('placeCall');

    tickWith($telephony);

    expect(deskStatus($tenant, $agent))->toBe(PresenceStatus::Ready);
});

it('dials nothing for a suspended client', function () {
    $tenant = Tenant::factory()->create();
    $agent = readyDeskOn($tenant);
    $campaign = dialingCampaignOn($tenant);
    leadOn($tenant, $campaign);
    $tenant->forceFill(['status' => TenantStatus::Suspended])->save();

    $telephony = fakeTelephony();
    $telephony->shouldNotReceive('placeCall');

    tickWith($telephony);

    expect(deskStatus($tenant, $agent))->toBe(PresenceStatus::Ready);
});

/**
 * 🔴 DP-9, and the reason the handler is made BEFORE the call is placed. An unanswered
 * call never enters our app — no StasisStart — so a ChannelDestroyed for a leg nobody
 * owns is its only event, and the switchboard drops those. Registering the leg at dial
 * time is what makes this reach a handler that can hand the desk back. Without it a desk
 * leaks on every unanswered dial, which is most of them.
 */
it('hands the desk back and counts the attempt when nobody answers', function () {
    $tenant = Tenant::factory()->create();
    $agent = readyDeskOn($tenant);
    $campaign = dialingCampaignOn($tenant);
    $lead = leadOn($tenant, $campaign);

    $telephony = fakeTelephony();
    $telephony->shouldReceive('placeCall')->once()->andReturn('customer-leg');

    $switchboard = tickWith($telephony);

    expect(deskStatus($tenant, $agent))->toBe(PresenceStatus::OnCall);

    $switchboard->handle(channelDestroyed('customer-leg'));

    expect(deskStatus($tenant, $agent))->toBe(PresenceStatus::Ready)
        ->and(TenantContext::run($tenant->id, fn () => $lead->fresh()->attempts))->toBe(1);

    // 🔴 And NO calls row. Reusing the outbound ringing state here files a phone that rang
    // out as CallOutcome::Abandoned — measured, live, when this was broken deliberately —
    // and abandoned is the number DP-12a counts against the 3% legal cap.
    expect(TenantContext::run($tenant->id, fn (): int => Call::query()->count()))->toBe(0);
});

/**
 * 🔴 The supervisor's board is published off the same listener loop that dials, and it
 * reads every live call's state through one exhaustive match. A dial in flight is a live
 * call in a state that match had never seen, so it threw — rescued into a log, which
 * meant NOTHING was published for ANY client on the box for as long as a number was
 * ringing, and a board that hears nothing blanks itself after twenty seconds.
 */
it('keeps publishing the supervisor board while a number is ringing', function () {
    $tenant = Tenant::factory()->create();
    readyDeskOn($tenant);
    $campaign = dialingCampaignOn($tenant);
    leadOn($tenant, $campaign);

    $telephony = fakeTelephony();
    $telephony->shouldReceive('placeCall')->once()->andReturn('customer-leg');

    $switchboard = tickWith($telephony);

    // A live call, and in none of the three columns: there is no caller on the line and
    // no desk's phone is ringing. Counting it as either would tell a supervisor somebody
    // is waiting when nobody has picked up yet.
    expect($switchboard->activeCallCount())->toBe(1)
        ->and($switchboard->tallyByTenant())->toBe([]);
});

/**
 * DIAL-1 A4 — the arrival. The customer picking up is what turns a held desk into a
 * ringing phone. From ringAgent() on it is the inbound path unchanged, so these prove the
 * three things A4 itself decides: which number reaches the agent, that a customer who was
 * never waiting is not recorded as having waited, and where a customer goes when the desk
 * they were promised does not answer.
 */
it('rings the booked desk with the CUSTOMER number showing when the customer answers', function () {
    $tenant = Tenant::factory()->create();
    $agent = readyDeskOn($tenant);
    $campaign = dialingCampaignOn($tenant);
    leadOn($tenant, $campaign);

    $telephony = fakeTelephony();
    $telephony->shouldReceive('placeCall')->once()
        ->with('PJSIP/9991234567', 'dialer', '+911400000000')
        ->andReturn('customer-leg');
    // 🔴 The CUSTOMER's number on the agent's phone, never ours. The console matches the
    // ringing number to a lead (lookupLead), so this argument IS the screen pop — the
    // other way round the agent sees the campaign's own 140 number and their screen pops
    // nothing.
    $telephony->shouldReceive('placeCall')->once()
        ->with('PJSIP/'.$agent->fresh()->sip_extension, 'agent', '9991234567', Mockery::any())
        ->andReturn('agent-leg');

    $switchboard = tickWith($telephony);
    $switchboard->handle(stasisStart('customer-leg', ['dialer']));

    expect(deskStatus($tenant, $agent))->toBe(PresenceStatus::OnCall);
});

it('writes the screen-pop note with no arrival time, because a dialled customer waited for nobody', function () {
    $tenant = Tenant::factory()->create();
    $agent = readyDeskOn($tenant);
    $campaign = dialingCampaignOn($tenant);
    leadOn($tenant, $campaign);

    $telephony = fakeTelephony();
    $telephony->shouldReceive('placeCall')->once()->andReturn('customer-leg');
    $telephony->shouldReceive('placeCall')->once()->andReturn('agent-leg');

    $switchboard = tickWith($telephony);
    $switchboard->handle(stasisStart('customer-leg', ['dialer']));

    $note = TenantContext::run($tenant->id, fn (): ?CallHandoff => CallHandoff::query()
        ->where('agent_user_id', $agent->id)->first());

    // 🔴 arrived_at is the caller's WAIT. We rang this customer and they picked up, so
    // there is none; the dial-to-answer seconds are not hold time. beginOutboundCall
    // passes null for the same reason, and filling it would inflate the average wait on
    // exactly the calls that had none.
    expect($note)->not->toBeNull()
        ->and($note->arrived_at)->toBeNull()
        // Which of OUR numbers the call is on — the campaign's, which names the campaign
        // on the report better than an inbound row ever gets to.
        ->and($note->dialled_number)->toBe('+911400000000');
});

/**
 * 🔴 Deliberate, not accidental. A live human is on this line because we rang them, so
 * the desk not answering must not cut them off — they go to the waiting room and the
 * sweep tries the next free desk, which is QD-4's second door working unchanged. It also
 * keeps one number honest: if they give up while holding, the waiting-room path files a
 * genuine CallOutcome::Abandoned, which is exactly what DP-12a counts against the 3% cap.
 * F9 kept ordinary no-answers OUT of that column; this puts the real abandons in.
 */
it('sends the dialled customer to the waiting room when the agent does not pick up', function () {
    $tenant = Tenant::factory()->create();
    $first = readyDeskOn($tenant);    // lower user id, so the tick reserves this one
    $second = readyDeskOn($tenant);
    $campaign = dialingCampaignOn($tenant);
    leadOn($tenant, $campaign);

    $telephony = fakeTelephony();
    $telephony->shouldReceive('placeCall')->once()->andReturn('customer-leg');
    $telephony->shouldReceive('placeCall')->once()
        ->with('PJSIP/'.$first->fresh()->sip_extension, 'agent', '9991234567', Mockery::any())
        ->andReturn('agent-leg');

    $switchboard = tickWith($telephony);
    $switchboard->handle(stasisStart('customer-leg', ['dialer']));
    $switchboard->handle(channelDestroyed('agent-leg'));

    // 🔴 Still a live call, so the customer was NOT hung up — a hangup disposes the
    // handler and this drops to zero. Asserted this way on purpose: the switchboard's
    // backstop catches everything a handler throws (FD-6), so a shouldNotReceive('hangup')
    // is swallowed and logged, and the test passes while the customer is cut off.
    expect($switchboard->activeCallCount())->toBe(1)
        ->and(deskStatus($tenant, $first))->toBe(PresenceStatus::Ready);

    // And the waiting room does its one move: the next free desk is offered the call,
    // the desk that just rang out being cooled off for one ring (S88).
    $telephony->shouldReceive('placeCall')->once()
        ->with('PJSIP/'.$second->fresh()->sip_extension, 'agent', '9991234567', Mockery::any())
        ->andReturn('second-agent-leg');

    $switchboard->sweepWaiting();

    expect(deskStatus($tenant, $second))->toBe(PresenceStatus::OnCall);
});

/**
 * 🔴 The row D3 exists to produce. A customer we RANG, who answered and then gave up while
 * the desk we promised them rang out, is a genuine abandoned call — the one DP-12a counts
 * against the 3% legal cap. Written as inbound it lands on the Missed Calls page, which
 * shows inbound rows only, so a supervisor sees somebody who rang US and gave up and calls
 * them back for a call they never made. Every reader also picks the customer's side of a
 * call off `direction` (Call::forCustomer, the export, the calls list), so the direction
 * and the two numbers have to move together or all three read our own number as the
 * customer.
 */
it('files a dialled customer who gives up as an outbound abandon, against their own lead and campaign', function () {
    $tenant = Tenant::factory()->create();
    readyDeskOn($tenant);
    $campaign = dialingCampaignOn($tenant);
    $lead = leadOn($tenant, $campaign);

    $telephony = fakeTelephony();
    $telephony->shouldReceive('placeCall')->once()->andReturn('customer-leg');
    $telephony->shouldReceive('placeCall')->once()->andReturn('agent-leg');

    $switchboard = tickWith($telephony);
    $switchboard->handle(stasisStart('customer-leg', ['dialer']));   // they picked up
    $switchboard->handle(channelDestroyed('agent-leg'));             // the desk never did
    $switchboard->handle(channelDestroyed('customer-leg'));          // and they gave up holding

    $call = TenantContext::run($tenant->id, fn (): ?Call => Call::query()->latest('id')->first());

    expect($call)->not->toBeNull()
        ->and($call->direction)->toBe(CallDirection::Outbound)
        ->and($call->outcome)->toBe(CallOutcome::Abandoned)
        // Ours first, theirs second — the pairing the console's own wrap-up writes.
        ->and($call->from_number)->toBe('+911400000000')
        ->and($call->to_number)->toBe('9991234567')
        // Named outright, not looked up: the number a dial presents is our outbound
        // caller-ID, and the lookup this used to fall back on reads the INBOUND map.
        ->and($call->lead_id)->toBe($lead->id)
        ->and($call->campaign_id)->toBe($campaign->id)
        // F17: they came on the line, so it counts as a try — uncounted, the lead sat at
        // the front of the list and was rung again once its claim lapsed.
        ->and(TenantContext::run($tenant->id, fn () => $lead->fresh()->attempts))->toBe(1);
});

/**
 * 🔴 The hold clock, and the one defect here that reaches a live person rather than a
 * report. `startedAt` is the zero of the wait — the maximum-hold cap measures from it, so
 * does the board's Longest Wait, so does the abandon row's "waited for". A3 stamped it
 * when the NUMBER WAS DIALLED, so every one of those three counted the seconds the phone
 * spent ringing into an empty room as hold the customer had already served. Measured
 * before the fix: on a 60-second cap, a customer who took 40 seconds to answer was cut off
 * 21 seconds into a hold they were owed 60 of, and their row read 61 seconds waited.
 *
 * The clock starts when they say hello, which is the only moment they are actually on the
 * line.
 */
it('starts the hold clock when the dialled customer answers, not when their phone started ringing', function () {
    $tenant = Tenant::factory()->create(['max_hold_seconds' => 60]);
    readyDeskOn($tenant);
    $campaign = dialingCampaignOn($tenant);
    leadOn($tenant, $campaign);

    $telephony = fakeTelephony();
    $telephony->shouldReceive('placeCall')->once()->andReturn('customer-leg');
    $telephony->shouldReceive('placeCall')->once()->andReturn('agent-leg');
    $telephony->shouldReceive('hangup')->andReturnNull();

    $switchboard = tickWith($telephony);

    // Forty seconds of ringing before they pick up — an ordinary answer, not a slow one.
    $this->travelTo(Carbon::parse('2026-09-10 12:00:40', 'Asia/Kolkata'));
    $switchboard->handle(stasisStart('customer-leg', ['dialer']));

    // The board's Longest Wait counts from the hello. Read off the tally rather than the
    // handler, because the tally is what a supervisor actually sees.
    expect($switchboard->tallyByTenant()[$tenant->id]['oldestWaitingAt'])
        ->toBe(Carbon::parse('2026-09-10 12:00:40', 'Asia/Kolkata')->getTimestamp());

    // Twenty-one seconds into an allowed sixty: still theirs, and still on the line.
    $this->travelTo(Carbon::parse('2026-09-10 12:01:01', 'Asia/Kolkata'));
    $switchboard->sweepWaiting();

    expect($switchboard->activeCallCount())->toBe(1);

    // And their row reports the hold they SERVED, not the ringing they did not. Asserted
    // as a duration off the abandon path on purpose: the cap always fires exactly
    // max_hold_seconds after whatever zero it was handed, so a row written by the cap
    // reads the same number from a wrong clock as from a right one. A customer hanging up
    // at a wall-clock moment of their own is the only reader that tells the two apart —
    // caught by break-testing this line, which stayed green measuring the cap.
    $this->travelTo(Carbon::parse('2026-09-10 12:01:20', 'Asia/Kolkata'));
    $switchboard->handle(channelDestroyed('customer-leg'));

    $call = TenantContext::run($tenant->id, fn (): ?Call => Call::query()->latest('id')->first());

    expect($call->outcome)->toBe(CallOutcome::Abandoned)
        ->and($call->waitedSeconds())->toBe(40);   // 12:00:40 hello -> 12:01:20 gave up
});

/**
 * 🔴 F15 — the row a SUCCESSFUL dial writes, and the common case rather than an edge one.
 * A dialled customer reaches the agent as a ring and a screen pop, identical to an inbound
 * caller from the console's side, so the console left its direction on the default and
 * every answered dial was filed as a call the customer made to US. Direction is what every
 * reader picks the customer's side off, and what an outbound-volume or abandoned-rate
 * report counts — and the listener's own abandon row already says Outbound, so filing the
 * answered ones as inbound left the two halves of one campaign disagreeing.
 *
 * End to end on purpose: the listener writing the flag and the console reading it are two
 * halves of one fact, and a test of either half alone passes while the chain is broken.
 */
it('files an answered dial as an outbound call, on the campaign number the dialer presented', function () {
    $tenant = Tenant::factory()->create();
    $agent = readyDeskOn($tenant);
    $campaign = dialingCampaignOn($tenant);
    leadOn($tenant, $campaign);

    $telephony = fakeTelephony();
    $telephony->shouldReceive('placeCall')->once()->andReturn('customer-leg');
    $telephony->shouldReceive('placeCall')->once()->andReturn('agent-leg');

    $switchboard = tickWith($telephony);
    $switchboard->handle(stasisStart('customer-leg', ['dialer']));   // they picked up

    $note = TenantContext::run($tenant->id, fn (): ?CallHandoff => CallHandoff::query()
        ->where('agent_user_id', $agent->id)->first());

    expect($note->was_dialled)->toBeTrue();

    // The agent talks to them and clicks Done. This is the one row a successful call gets.
    $this->actingAs($agent);
    $console = new AgentConsole;
    $console->callCorrelationId = $note->ticket;
    $console->callPartyNumber = '9991234567';

    TenantContext::run($tenant->id, fn () => $console->completeUnmatched());

    $call = TenantContext::run($tenant->id, fn (): ?Call => Call::query()->latest('id')->first());

    expect($call->direction)->toBe(CallDirection::Outbound)
        // Ours first, theirs second — and OURS is the campaign's own caller ID, which the
        // note carries. The config default would file every campaign under the system
        // number, which is the whole reason DQ-5 makes campaigns carry their own.
        ->and($call->from_number)->toBe('+911400000000')
        ->and($call->to_number)->toBe('9991234567');
});
