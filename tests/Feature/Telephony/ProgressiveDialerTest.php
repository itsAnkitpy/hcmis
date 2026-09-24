<?php

use App\Enums\CallDirection;
use App\Enums\CallEndedBy;
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
use App\Telephony\RecordingSession;
use App\Telephony\TelephonyException;
use App\Telephony\TelephonyProvider;
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
it('hands the desk back, counts one attempt and files an outbound no-answer when nobody answers', function () {
    $tenant = Tenant::factory()->create();
    $agent = readyDeskOn($tenant);
    $campaign = dialingCampaignOn($tenant);
    $lead = leadOn($tenant, $campaign);

    $telephony = fakeTelephony();
    $telephony->shouldReceive('placeCall')->once()->andReturn('customer-leg');

    $switchboard = tickWith($telephony);

    expect(deskStatus($tenant, $agent))->toBe(PresenceStatus::OnCall);

    $switchboard->handle(channelDestroyed('customer-leg'));

    // One try, not two: the row's writer counts it, so the ring-out no longer does.
    expect(deskStatus($tenant, $agent))->toBe(PresenceStatus::Ready)
        ->and(TenantContext::run($tenant->id, fn () => $lead->fresh()->attempts))->toBe(1);

    // 🔴 F21 — a row, because DP-12a divides by every dial placed. But NEVER Abandoned:
    // reusing the outbound ringing state here once filed a phone that rang out as
    // CallOutcome::Abandoned — measured, live, when this was broken deliberately — and
    // abandoned is the top of the 3% legal cap.
    $call = TenantContext::run($tenant->id, fn (): Call => Call::query()->sole());

    expect($call->direction)->toBe(CallDirection::Outbound)
        ->and($call->outcome)->toBe(CallOutcome::NoAnswer)
        ->and($call->was_dialled)->toBeTrue()
        ->and($call->agent_id)->toBeNull()
        ->and($call->started_at)->toBeNull()   // nobody came on the line
        ->and($call->lead_id)->toBe($lead->id)
        ->and($call->campaign_id)->toBe($campaign->id);
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
        ->and($call->ended_by)->toBe(CallEndedBy::Customer)
        ->and($call->was_dialled)->toBeTrue()
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
 * 🔴 F22 — the hold limit cutting off a dialled customer is an ABANDONED call. TRAI counts
 * a call the person answered and no agent reached, however it ended, and this is the top of
 * DP-12a's 3%. Filed the inbound way, as a no-answer, a floor whose desks never pick up
 * would read 0% abandoned. It is still us who ended it, and the row says so.
 */
it('files a dialled customer the hold limit cuts off as abandoned, ended by us', function () {
    $tenant = Tenant::factory()->create(['max_hold_seconds' => 44]);
    readyDeskOn($tenant);
    $campaign = dialingCampaignOn($tenant);
    $lead = leadOn($tenant, $campaign);

    $telephony = fakeTelephony();
    $telephony->shouldReceive('placeCall')->once()->andReturn('customer-leg');
    $telephony->shouldReceive('placeCall')->once()->andReturn('agent-leg');
    $telephony->shouldReceive('isAnswered')->with('agent-leg')->andReturnFalse();
    $telephony->shouldReceive('hangup')->andReturnNull();

    $switchboard = tickWith($telephony);
    $switchboard->handle(stasisStart('customer-leg', ['dialer']));   // hello — the desk rings

    $this->travel(45)->seconds();
    $switchboard->sweepWaiting();                                     // and never picks up

    $call = TenantContext::run($tenant->id, fn (): Call => Call::query()->sole());

    expect($call->outcome)->toBe(CallOutcome::Abandoned)
        ->and($call->ended_by)->toBe(CallEndedBy::System)
        ->and($call->was_dialled)->toBeTrue()
        ->and(TenantContext::run($tenant->id, fn () => $lead->fresh()->attempts))->toBe(1);
});

it('still hangs up on a dialled customer at the hold limit when the client takes messages (AU-29, S163)', function () {
    $tenant = Tenant::factory()->create([
        'max_hold_seconds' => 44,
        'voicemail_enabled' => true,
        'voicemail_greeting_path' => 'voicemail-greeting/1/'.str_repeat('d', 64).'.wav',
    ]);
    readyDeskOn($tenant);
    $campaign = dialingCampaignOn($tenant);
    leadOn($tenant, $campaign);

    $telephony = fakeTelephony();
    $telephony->shouldReceive('placeCall')->once()->andReturn('customer-leg');
    $telephony->shouldReceive('placeCall')->once()->andReturn('agent-leg');
    $telephony->shouldReceive('isAnswered')->with('agent-leg')->andReturnFalse();
    $telephony->shouldReceive('hangup')->with('customer-leg')->once();
    $telephony->shouldReceive('hangup')->with('agent-leg');
    $telephony->shouldNotReceive('play');
    $telephony->shouldNotReceive('recordMessage');

    $switchboard = tickWith($telephony);
    $switchboard->handle(stasisStart('customer-leg', ['dialer']));

    $this->travel(45)->seconds();
    $switchboard->sweepWaiting();

    expect(TenantContext::run($tenant->id, fn (): Call => Call::query()->sole())->outcome)->toBe(CallOutcome::Abandoned)
        ->and($switchboard->activeCallCount())->toBe(0);
});

/**
 * 🔴 F20 — found live on staging. The hold limit ran out in the same second the agent
 * picked up the desk. The listener sweeps before it hands over the event it is holding,
 * so the limit won: the customer was hung up on, the agent's console still saw a call and
 * made them wrap it up, and one call left a no-answer row, an answered row and two tries.
 * A pick-up Asterisk already reports now wins, and its own arrival connects the call.
 */
it('lets a desk that has already picked up win over the hold limit', function () {
    $tenant = Tenant::factory()->create(['max_hold_seconds' => 44]);
    readyDeskOn($tenant);
    $campaign = dialingCampaignOn($tenant);
    $lead = leadOn($tenant, $campaign);

    $telephony = fakeTelephony();
    $telephony->shouldReceive('placeCall')->once()->andReturn('customer-leg');
    $telephony->shouldReceive('placeCall')->once()->andReturn('agent-leg');
    $telephony->shouldReceive('isAnswered')->with('agent-leg')->andReturnTrue();
    $telephony->shouldReceive('join')->once()->andReturn('conv-1');
    $telephony->shouldReceive('startRecording')->once()->andReturn(
        new RecordingSession('customer-leg', 'call-1', 'snoop-said', 'snoop-heard')
    );
    $telephony->shouldReceive('hangup')->andReturnNull();

    $switchboard = tickWith($telephony);
    $switchboard->handle(stasisStart('customer-leg', ['dialer']));   // hello — the desk rings

    // 45 seconds on, the limit has run out — but the desk has picked up and that event is
    // still on its way, exactly where the sweep caught it on staging.
    $this->travel(45)->seconds();
    $switchboard->sweepWaiting();
    $switchboard->handle(stasisStart('agent-leg', ['agent']));

    // Still one live call, and nothing written or counted by the listener: the call is the
    // console's to wrap up, which writes the one row and counts the one try.
    expect($switchboard->activeCallCount())->toBe(1)
        ->and(TenantContext::run($tenant->id, fn (): int => Call::query()->count()))->toBe(0)
        ->and(TenantContext::run($tenant->id, fn () => $lead->fresh()->attempts))->toBe(0);
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
        // F25: the note is pruned on this agent's next ring, so the row keeps the fact.
        ->and($call->was_dialled)->toBeTrue()
        // Ours first, theirs second — and OURS is the campaign's own caller ID, which the
        // note carries. The config default would file every campaign under the system
        // number, which is the whole reason DQ-5 makes campaigns carry their own.
        ->and($call->from_number)->toBe('+911400000000')
        ->and($call->to_number)->toBe('9991234567');
});

/**
 * 🔴 F28, measured on staging 2026-09-13 and the reason slice 4's reported 4 / 2 / 50.0%
 * was a coincidence — the honest figures for the day were 5 / 3 / 60.0%.
 *
 * We rang this person. They answered. The switch then refused to ring the desk booked for
 * them ("Allocation failed", F29's stale WebRTC registration), and the refusal reached
 * abort(), which hung up every leg. So a human who said hello got dead air, the state was
 * still DialingCustomer — which recordMissedCallIfNeverConnected() skipped — and the call
 * was recorded NOWHERE: missing from both halves of DP-12a's 3% cap in the direction that
 * flatters us, and missing its attempt, so the dialer would ring them again too soon.
 *
 * They now hold instead, and the ordinary hold-limit path files them.
 */
it('keeps a dialled customer who answered on the line when the switch refuses their desk (F28)', function () {
    $tenant = Tenant::factory()->create(['max_hold_seconds' => 44]);
    $agent = readyDeskOn($tenant);
    $campaign = dialingCampaignOn($tenant);
    $lead = leadOn($tenant, $campaign);

    $telephony = fakeTelephony();
    $telephony->shouldReceive('placeCall')->once()->andReturn('customer-leg');
    $telephony->shouldReceive('placeCall')->once()->andThrow(new TelephonyException('Allocation failed'));
    $telephony->shouldReceive('hangup')->andReturnNull();

    $switchboard = tickWith($telephony);
    $switchboard->handle(stasisStart('customer-leg', ['dialer']));   // hello — and the desk refuses

    // Still on the line: nothing filed yet, because nothing has ended. The old code had
    // hung them up by this point and written nothing at all.
    expect(TenantContext::run($tenant->id, fn (): int => Call::query()->count()))->toBe(0)
        // The booking was still PENDING when the refusal landed, so only this hands it
        // back — otherwise the desk sits tagged "On a call" for good (Fold B).
        ->and(deskStatus($tenant, $agent))->toBe(PresenceStatus::Ready);

    $this->travel(45)->seconds();
    $switchboard->sweepWaiting();

    $call = TenantContext::run($tenant->id, fn (): Call => Call::query()->sole());

    // Row 619's shape, through row 619's code: a person answered a call we placed and no
    // agent reached them, which is what TRAI counts.
    expect($call->outcome)->toBe(CallOutcome::Abandoned)
        ->and($call->ended_by)->toBe(CallEndedBy::System)
        ->and($call->direction)->toBe(CallDirection::Outbound)
        ->and($call->was_dialled)->toBeTrue()
        ->and($call->lead_id)->toBe($lead->id)
        ->and($call->campaign_id)->toBe($campaign->id)
        ->and($call->started_at)->not->toBeNull()
        ->and(TenantContext::run($tenant->id, fn (): int => $lead->fresh()->attempts))->toBe(1);
});

/**
 * The net under the catch above, and the reason DialingCustomer is in
 * recordMissedCallIfNeverConnected()'s guard. Not every verb on the way to a ringing desk
 * is inside that catch — the hold music is started first and has no rescue of its own — so
 * a refusal there still tears the call down the old way. The customer had already said
 * hello, which makes it an abandon however it ended (F22), and before this the state was
 * DialingCustomer and the guard walked straight past it.
 */
it('still files a dialled customer who answered when a verb before the ring is refused', function () {
    $tenant = Tenant::factory()->create();
    readyDeskOn($tenant);
    $campaign = dialingCampaignOn($tenant);
    $lead = leadOn($tenant, $campaign);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('placeCall')->once()->andReturn('customer-leg');
    $telephony->shouldReceive('startHoldMusic')->once()->andThrow(new TelephonyException('Allocation failed'));
    $telephony->shouldReceive('hangup')->once()->with('customer-leg');

    $switchboard = tickWith($telephony);
    $switchboard->handle(stasisStart('customer-leg', ['dialer']));

    $call = TenantContext::run($tenant->id, fn (): Call => Call::query()->sole());

    expect($call->outcome)->toBe(CallOutcome::Abandoned)
        ->and($call->direction)->toBe(CallDirection::Outbound)
        ->and($call->was_dialled)->toBeTrue()
        ->and($call->lead_id)->toBe($lead->id)
        ->and(TenantContext::run($tenant->id, fn (): int => $lead->fresh()->attempts))->toBe(1);
});

// --- DP-14: the retry gap. A lead we rang recently is not rung again. ---

/**
 * The gap lives in its own scope, chained at the dialer's call site only. These
 * prove the two halves that matter: the dialer honours it, and `callable()` — the
 * rule the agent console shares — does not, so a human ringing somebody back after
 * forty minutes is unaffected.
 *
 * Time is read off `calls.created_at`: `started_at` is trunk-era enrichment and is
 * null in v1, so it is the only moment on the row that is always populated.
 */
function rangLeadMinutesAgo(Tenant $tenant, Lead $lead, int $minutesAgo, ?User $agent = null, array $attributes = []): void
{
    TenantContext::run($tenant->id, function () use ($lead, $minutesAgo, $agent, $attributes) {
        $call = Call::factory()->forLead($lead);

        if ($agent !== null) {
            $call = $call->forAgent($agent);
        }

        $call->create([
            'direction' => CallDirection::Outbound,
            'outcome' => CallOutcome::NoAnswer,
            'created_at' => now()->subMinutes($minutesAgo),
            ...$attributes,
        ]);
    });
}

it('does not dial a lead again inside the campaign retry gap', function () {
    $tenant = Tenant::factory()->create();
    $agent = readyDeskOn($tenant);
    $campaign = dialingCampaignOn($tenant);
    $lead = leadOn($tenant, $campaign);
    rangLeadMinutesAgo($tenant, $lead, 30);   // default gap is 120

    $telephony = fakeTelephony();
    $telephony->shouldNotReceive('placeCall');

    tickWith($telephony);

    // Nothing dialled and the desk handed straight back — the same shape as an
    // empty pool, because to the dialer that is exactly what this is.
    expect(deskStatus($tenant, $agent))->toBe(PresenceStatus::Ready)
        ->and(TenantContext::run($tenant->id, fn () => $lead->fresh()->claimed_at))->toBeNull();
});

it('dials the lead again once the retry gap has passed', function () {
    $tenant = Tenant::factory()->create();
    readyDeskOn($tenant);
    $campaign = dialingCampaignOn($tenant);
    $lead = leadOn($tenant, $campaign);
    rangLeadMinutesAgo($tenant, $lead, 180);

    $telephony = fakeTelephony();
    $telephony->shouldReceive('placeCall')->once()->andReturn('customer-leg');

    tickWith($telephony);

    expect(TenantContext::run($tenant->id, fn () => $lead->fresh()->claimed_at))->not->toBeNull();
});

it('counts a call an agent made by hand against the gap, not only the dialer\'s own', function () {
    // From the customer's side it is the same company ringing twice in half an hour.
    $tenant = Tenant::factory()->create();
    $agent = readyDeskOn($tenant);
    $campaign = dialingCampaignOn($tenant);
    $lead = leadOn($tenant, $campaign);
    rangLeadMinutesAgo($tenant, $lead, 30, $agent);

    $telephony = fakeTelephony();
    $telephony->shouldNotReceive('placeCall');

    tickWith($telephony);

    expect(TenantContext::run($tenant->id, fn () => $lead->fresh()->claimed_at))->toBeNull();
});

it('reads the gap off the campaign rather than a fixed two hours', function () {
    $tenant = Tenant::factory()->create();
    readyDeskOn($tenant);
    $campaign = dialingCampaignOn($tenant);
    TenantContext::run($tenant->id, fn () => $campaign->update(['retry_gap_minutes' => 15]));
    $lead = leadOn($tenant, $campaign);
    rangLeadMinutesAgo($tenant, $lead, 30);   // inside two hours, outside this campaign's gap

    $telephony = fakeTelephony();
    $telephony->shouldReceive('placeCall')->once()->andReturn('customer-leg');

    tickWith($telephony);

    expect(TenantContext::run($tenant->id, fn () => $lead->fresh()->claimed_at))->not->toBeNull();
});

it('leaves the shared serving rule alone, so the agent console still offers a lead rung ten minutes ago', function () {
    // 🔴 The guard on DP-14's promise. callable() is AgentConsole::nextCallableLead's
    // rule too; if the gap ever leaks into it, a floor silently loses its callbacks.
    $tenant = Tenant::factory()->create();
    $campaign = dialingCampaignOn($tenant);
    $lead = leadOn($tenant, $campaign);
    rangLeadMinutesAgo($tenant, $lead, 10);

    $served = TenantContext::run($tenant->id, fn (): ?Lead => Lead::query()->callable($campaign->id)->first());

    expect($served?->id)->toBe($lead->id);
});

it('holds the dialer off a customer we spoke to, even though they rang us', function () {
    // The wrap-up files an inbound conversation against the same lead (the console
    // matches on phone number), so it lands in the history the gap reads. Deliberate:
    // ringing somebody an hour after they called us is the same nuisance either way.
    $tenant = Tenant::factory()->create();
    $agent = readyDeskOn($tenant);
    $campaign = dialingCampaignOn($tenant);
    $lead = leadOn($tenant, $campaign);
    rangLeadMinutesAgo($tenant, $lead, 30, $agent, [
        'direction' => CallDirection::Inbound,
        'outcome' => CallOutcome::Answered,
    ]);

    $telephony = fakeTelephony();
    $telephony->shouldNotReceive('placeCall');

    tickWith($telephony);

    expect(TenantContext::run($tenant->id, fn () => $lead->fresh()->claimed_at))->toBeNull();
});

it('still dials somebody who rang us and gave up before anyone answered', function () {
    // 🔴 The other half, and the one that would hurt if it went the other way. A missed
    // inbound call is written with NO lead attached, so it never enters this lead's
    // history and never holds the dialer off — the person who tried to reach us is
    // called straight back rather than parked for two hours.
    $tenant = Tenant::factory()->create();
    readyDeskOn($tenant);
    $campaign = dialingCampaignOn($tenant);
    $lead = leadOn($tenant, $campaign);

    TenantContext::run($tenant->id, fn () => Call::factory()->create([
        'direction' => CallDirection::Inbound,
        'outcome' => CallOutcome::Abandoned,
        'lead_id' => null,
        'to_number' => $lead->phone,
        'created_at' => now()->subMinutes(5),
    ]));

    $telephony = fakeTelephony();
    $telephony->shouldReceive('placeCall')->once()->andReturn('customer-leg');

    tickWith($telephony);

    expect(TenantContext::run($tenant->id, fn () => $lead->fresh()->claimed_at))->not->toBeNull();
});
