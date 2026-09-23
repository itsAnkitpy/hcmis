<?php

use App\Enums\CallOutcome;
use App\Enums\ClosedHours;
use App\Enums\DncSource;
use App\Enums\MenuAction;
use App\Enums\MissedReason;
use App\Enums\PresenceStatus;
use App\Models\AgentPresence;
use App\Models\Call;
use App\Models\CallHandoff;
use App\Models\Department;
use App\Models\DncEntry;
use App\Models\Menu;
use App\Models\Tenant;
use App\Models\User;
use App\Telephony\AriNotFound;
use App\Telephony\Flows\CallFlowState;
use App\Telephony\Flows\CallToAgentFlow;
use App\Telephony\Flows\Switchboard;
use App\Telephony\TelephonyException;
use App\Telephony\TelephonyProvider;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

/**
 * inbound-audio.md slice 6 (AU-17 … AU-28) — the spoken menu.
 *
 * A caller on a number with a menu is answered, hears a greeting, and presses a key.
 * Saying nothing or pressing a key the menu does not offer are the SAME miss and share
 * one limit: the greeting once more, then a desk with "No choice made".
 *
 * 🔴 THE FIVE-SECOND WAIT IS TIMED BY THE PHONE SYSTEM, NOT OUR HEARTBEAT (S163). The
 * greeting and a built-in five seconds of silence go over as ONE play, and that play
 * finishing with no key pressed IS the timeout. So these tests never travel in time —
 * they send the finish event, which is exactly what the engine does.
 *
 * The decisions these prove (Ankit, S169):
 *   Q1  menu sounds hang off the MENU; the media route stops asking who owns a file
 *   Q3  the second try is the identical play, and the play id tells the two apart
 *   Q4  a caller at the menu is counted in no column on the wall board
 *   Q5  a caller at the menu is still covered by the client's maximum hold
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    fakeAgentDirectory();
    Queue::fake();
});

afterEach(fn () => TenantContext::forget());

/** An open client, so the hours check (AU-21) never gets in the way. */
function openClient(): Tenant
{
    $week = array_fill_keys(
        ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
        ['open' => '00:00', 'close' => '00:00'],   // 24 hours
    );

    return Tenant::factory()->create([
        'settings' => ['hours' => $week, 'closed_hours' => ClosedHours::NoPickup->value],
    ]);
}

/** A menu with a greeting and whatever keys the test needs, owned by $tenant. */
function menuFor(Tenant $tenant, array $options = []): Menu
{
    return TenantContext::run($tenant->id, fn (): Menu => Menu::factory()
        ->withGreeting('menu-greeting/'.$tenant->id.'/'.str_repeat('c', 64).'.wav')
        ->create(['options' => $options]));
}

/** One key, in the shape `menus.options` holds. */
function menuKey(string $key, MenuAction $action, string $label, ?string $soundPath = null): array
{
    return [
        'key' => $key,
        'label' => $label,
        'action' => $action->value,
        'sound_path' => $soundPath,
        'sound_rights_confirmed' => $soundPath !== null,
    ];
}

/** An inbound caller who reaches the menu on this client's number. */
function callerAtMenu(Tenant $tenant, Menu $menu, TelephonyProvider $telephony): CallToAgentFlow
{
    fakeNumberDirectory($menu->id);

    $flow = new CallToAgentFlow($telephony, new Switchboard($telephony));
    $flow->handle(stasisStart('caller-leg', [], '9998887777', (string) $tenant->id));

    return $flow;
}

/** The greeting plus the built-in five seconds, as the engine receives them. */
function greetingPlay(Menu $menu): array
{
    return [$menu->greetingUrl(), 'silence/5'];
}

it('answers and plays the greeting and five seconds of silence as ONE play (AU-23, S163)', function () {
    $tenant = openClient();
    $menu = menuFor($tenant, [menuKey('1', MenuAction::TalkToAgent, 'Sales')]);
    fakeAgentRouter(6);   // a free agent must NOT be rung — the menu comes first

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-leg');
    // 🔴 ONE play, TWO sounds. Two separate plays would queue, and the second would be
    // the timeout for a greeting that had already finished — the wait would be wrong and
    // there would be two finish events to tell apart.
    $telephony->shouldReceive('play')->once()
        ->with('caller-leg', greetingPlay($menu))
        ->andReturn('play-1');

    $flow = callerAtMenu($tenant, $menu, $telephony);

    expect($flow->state())->toBe(CallFlowState::InMenu);
});

it('rings an agent on "talk to an agent" and saves what they chose (AU-17, AU-25)', function () {
    $tenant = openClient();
    $menu = menuFor($tenant, [menuKey('1', MenuAction::TalkToAgent, 'Sales')]);
    // A real agent row: the ring-time note has a foreign key to users, and AU-25's whole
    // point is what that note carries.
    fakeAgentRouter(clientUserWithRole($tenant, 'agent')->id);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('play')->once()->andReturn('play-1');
    $telephony->shouldReceive('stopPlayback')->once()->with('play-1');
    $telephony->shouldReceive('startHoldMusic')->once()->with('caller-leg', null);
    $telephony->shouldReceive('placeCall')->once()->andReturn('agent-leg');

    $flow = callerAtMenu($tenant, $menu, $telephony);
    $flow->handle(dtmfReceived('1', 'caller-leg'));

    expect($flow->state())->toBe(CallFlowState::RingingAgent);

    // AU-25: it rides to the agent's screen on the note that is written before the ring.
    $note = TenantContext::cross(fn () => CallHandoff::query()->sole());
    expect($note->menu_choice)->toBe('Sales');
});

it('waits with the others when nobody is free after "talk to an agent"', function () {
    $tenant = openClient();
    $menu = menuFor($tenant, [menuKey('1', MenuAction::TalkToAgent, 'Sales')]);
    fakeAgentRouter(null);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('play')->once()->andReturn('play-1');
    $telephony->shouldReceive('stopPlayback')->once();
    $telephony->shouldReceive('startHoldMusic')->once()->with('caller-leg', null);

    $flow = callerAtMenu($tenant, $menu, $telephony);
    $flow->handle(dtmfReceived('1', 'caller-leg'));

    expect($flow->state())->toBe(CallFlowState::Waiting);
});

it('plays the key\'s own message and ends the call on "hear a message" (AU-17)', function () {
    $tenant = openClient();
    $soundPath = 'menu-option/'.$tenant->id.'/'.str_repeat('d', 64).'.wav';
    $menu = menuFor($tenant, [menuKey('2', MenuAction::HearMessage, 'Opening hours', $soundPath)]);
    fakeAgentRouter(6);   // a free agent must not be rung — this key never reaches one

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('play')->once()->with('caller-leg', Mockery::type('array'))->andReturn('play-1');
    $telephony->shouldReceive('stopPlayback')->once()->with('play-1');

    $flow = callerAtMenu($tenant, $menu, $telephony);

    // 🔴 The address is the menu's own signed one, not a guess. S167 shipped a play whose
    // media value Asterisk silently skipped, so this asserts the value the rest of the
    // system builds rather than one invented here.
    $telephony->shouldReceive('play')->once()
        ->with('caller-leg', [$menu->soundUrlFor($menu->optionFor('2'))])
        ->andReturn('play-2');

    $flow->handle(dtmfReceived('2', 'caller-leg'));

    expect($flow->state())->toBe(CallFlowState::PlayingMenuMessage);

    $telephony->shouldReceive('hangup')->once()->with('caller-leg');
    $flow->handle(playbackFinished('play-2', 'caller-leg'));

    expect($flow->state())->toBe(CallFlowState::Idle);
});

it('adds the caller to the do-not-call list straight away, then confirms and ends (AU-28)', function () {
    $tenant = openClient();
    $soundPath = 'menu-option/'.$tenant->id.'/'.str_repeat('e', 64).'.wav';
    $menu = menuFor($tenant, [menuKey('9', MenuAction::RemoveFromList, 'Take me off your list', $soundPath)]);
    fakeAgentRouter(null);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('play')->twice()->andReturn('play-1', 'play-2');
    $telephony->shouldReceive('stopPlayback')->once();

    $flow = callerAtMenu($tenant, $menu, $telephony);
    $flow->handle(dtmfReceived('9', 'caller-leg'));

    // 🔴 ADDED BEFORE THE CONFIRMATION PLAYS, not after it finishes. AU-28 is
    // add-then-disconnect: a caller who hangs up on their own confirmation is still off
    // the list.
    $entry = TenantContext::cross(fn () => DncEntry::query()->sole());
    expect($entry->tenant_id)->toBe($tenant->id)
        ->and($entry->phone)->toBe('9998887777')
        ->and($entry->source)->toBe(DncSource::CustomerRequest);
});

it('ends the call with no sound when the client uploaded no confirmation (AU-28)', function () {
    $tenant = openClient();
    $menu = menuFor($tenant, [menuKey('9', MenuAction::RemoveFromList, 'Take me off your list')]);
    fakeAgentRouter(null);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('play')->once()->andReturn('play-1');   // the greeting only
    $telephony->shouldReceive('stopPlayback')->once();
    $telephony->shouldReceive('hangup')->once()->with('caller-leg');

    $flow = callerAtMenu($tenant, $menu, $telephony);
    $flow->handle(dtmfReceived('9', 'caller-leg'));

    expect($flow->state())->toBe(CallFlowState::Idle);
    expect(TenantContext::cross(fn () => DncEntry::query()->count()))->toBe(1);
});

it('stops the greeting the moment a key is pressed, part way through it (AU-22, R3)', function () {
    $tenant = openClient();
    $menu = menuFor($tenant, [menuKey('1', MenuAction::TalkToAgent, 'Sales')]);
    fakeAgentRouter(null);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('play')->once()->andReturn('play-1');
    // A key does NOT stop a playing greeting by itself (R3) — this is our job, and it is
    // the id of the play that is running, not a guess.
    $telephony->shouldReceive('stopPlayback')->once()->with('play-1');
    $telephony->shouldReceive('startHoldMusic')->once();

    $flow = callerAtMenu($tenant, $menu, $telephony);
    $flow->handle(dtmfReceived('1', 'caller-leg'));
});

it('says the greeting once more when the caller says nothing, then puts them through (AU-23)', function () {
    $tenant = openClient();
    $menu = menuFor($tenant, [menuKey('1', MenuAction::TalkToAgent, 'Sales')]);
    fakeAgentRouter(clientUserWithRole($tenant, 'agent')->id);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    // 🔴 THE SECOND TRY IS THE IDENTICAL PLAY (Ankit, S169). One code path for both kinds
    // of miss, and the wait stays exactly five seconds on the second pass.
    $telephony->shouldReceive('play')->twice()
        ->with('caller-leg', greetingPlay($menu))
        ->andReturn('play-1', 'play-2');
    $telephony->shouldReceive('startHoldMusic')->once();
    $telephony->shouldReceive('placeCall')->once()->andReturn('agent-leg');

    $flow = callerAtMenu($tenant, $menu, $telephony);

    $flow->handle(playbackFinished('play-1', 'caller-leg'));    // first silence
    expect($flow->state())->toBe(CallFlowState::InMenu);

    $flow->handle(playbackFinished('play-2', 'caller-leg'));    // second silence
    expect($flow->state())->toBe(CallFlowState::RingingAgent);

    $note = TenantContext::cross(fn () => CallHandoff::query()->sole());
    expect($note->menu_choice)->toBe('No choice made');
});

it('shares one limit between a wrong key and silence, in either order (AU-24)', function (array $misses) {
    $tenant = openClient();
    $menu = menuFor($tenant, [menuKey('1', MenuAction::TalkToAgent, 'Sales')]);
    fakeAgentRouter(clientUserWithRole($tenant, 'agent')->id);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('play')->twice()->andReturn('play-1', 'play-2');
    $telephony->shouldReceive('stopPlayback')->zeroOrMoreTimes();
    $telephony->shouldReceive('startHoldMusic')->once();
    $telephony->shouldReceive('placeCall')->once()->andReturn('agent-leg');

    $flow = callerAtMenu($tenant, $menu, $telephony);

    $playbackIds = ['play-1', 'play-2'];

    foreach ($misses as $index => $miss) {
        $miss === 'silence'
            ? $flow->handle(playbackFinished($playbackIds[$index], 'caller-leg'))
            : $flow->handle(dtmfReceived('7', 'caller-leg'));     // 7 is not on this menu
    }

    expect($flow->state())->toBe(CallFlowState::RingingAgent);

    $note = TenantContext::cross(fn () => CallHandoff::query()->sole());
    expect($note->menu_choice)->toBe('No choice made');
})->with([
    'silence twice' => [['silence', 'silence']],
    'wrong key twice' => [['wrong', 'wrong']],
    'silence then a wrong key' => [['silence', 'wrong']],
    'a wrong key then silence' => [['wrong', 'silence']],
]);

it('ignores the stopped greeting reporting in after the replay has started', function () {
    // 🔴 THE CASE SLICE 5'S TRICK DOES NOT COVER. A stopped play reports the same `done`
    // as one that ran out, and slice 5 leaned on the call having LEFT the waiting area by
    // then. A caller who presses a wrong key is still standing at the menu, so only the
    // play id tells the two passes apart — it is replaced before the old one reports in.
    $tenant = openClient();
    $menu = menuFor($tenant, [menuKey('1', MenuAction::TalkToAgent, 'Sales')]);
    fakeAgentRouter(6);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    // TWICE, not three times. A third would mean the stale report was read as a miss.
    $telephony->shouldReceive('play')->twice()->andReturn('play-1', 'play-2');
    $telephony->shouldReceive('stopPlayback')->once()->with('play-1');

    $flow = callerAtMenu($tenant, $menu, $telephony);

    $flow->handle(dtmfReceived('7', 'caller-leg'));             // wrong key: stop, replay
    $flow->handle(playbackFinished('play-1', 'caller-leg'));    // the STOPPED one reports in

    expect($flow->state())->toBe(CallFlowState::InMenu);
});

it('keeps the caller when the engine refuses to stop the greeting', function () {
    // Two ways this is refused and both are ordinary: the greeting ended a moment ago
    // (404), or the replay has been asked for and has not started talking yet (500). Our
    // phone layer turns every refusal into an exception, and this is the hot path of
    // every single key press — unswallowed it would tear the call down.
    $tenant = openClient();
    $menu = menuFor($tenant, [menuKey('1', MenuAction::TalkToAgent, 'Sales')]);
    fakeAgentRouter(6);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('play')->once()->andReturn('play-1');
    $telephony->shouldReceive('stopPlayback')->once()->andThrow(new TelephonyException('no such playback'));
    $telephony->shouldReceive('startHoldMusic')->once();
    $telephony->shouldReceive('placeCall')->once()->andReturn('agent-leg');

    $flow = callerAtMenu($tenant, $menu, $telephony);
    $flow->handle(dtmfReceived('1', 'caller-leg'));

    expect($flow->state())->toBe(CallFlowState::RingingAgent);
});

it('ignores a key pressed on a leg that is not the caller\'s', function () {
    $tenant = openClient();
    $menu = menuFor($tenant, [menuKey('1', MenuAction::TalkToAgent, 'Sales')]);
    fakeAgentRouter(6);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('play')->once()->andReturn('play-1');

    $flow = callerAtMenu($tenant, $menu, $telephony);
    $flow->handle(dtmfReceived('1', 'someone-elses-leg'));

    expect($flow->state())->toBe(CallFlowState::InMenu);
});

it('files a caller who hangs up while the menu is asking, marked "hung up in menu" (AU-26)', function () {
    $tenant = openClient();
    $menu = menuFor($tenant, [menuKey('1', MenuAction::TalkToAgent, 'Sales')]);
    fakeAgentRouter(null);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('play')->once()->andReturn('play-1');

    $flow = callerAtMenu($tenant, $menu, $telephony);
    $flow->handle(stasisEnd('caller-leg'));

    $call = TenantContext::cross(fn () => Call::query()->sole());

    expect($call->outcome)->toBe(CallOutcome::Abandoned)           // they gave up
        ->and($call->missed_reason)->toBe(MissedReason::HungUpInMenu)
        ->and($call->agent_id)->toBeNull();

    // AU-26: this one DOES belong on the callback list.
    expect($call->missed_reason->showsOnMissedCalls())->toBeTrue();
});

it('writes a call record for a served caller and keeps it off Missed Calls (AUQ-3, AU-26)', function () {
    $tenant = openClient();
    $soundPath = 'menu-option/'.$tenant->id.'/'.str_repeat('d', 64).'.wav';
    $menu = menuFor($tenant, [menuKey('2', MenuAction::HearMessage, 'Opening hours', $soundPath)]);
    fakeAgentRouter(null);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('play')->twice()->andReturn('play-1', 'play-2');
    $telephony->shouldReceive('stopPlayback')->once();

    $flow = callerAtMenu($tenant, $menu, $telephony);
    $flow->handle(dtmfReceived('2', 'caller-leg'));

    $call = TenantContext::cross(fn () => Call::query()->sole());

    expect($call->missed_reason)->toBe(MissedReason::ServedByMenu)
        ->and($call->menu_choice)->toBe('Opening hours')
        ->and($call->missed_reason->showsOnMissedCalls())->toBeFalse();
});

it('writes exactly one record however the served caller\'s message ends', function () {
    // The row goes in when the key is taken, before a sound plays — so the caller hanging
    // up on their own message must not write a second one.
    $tenant = openClient();
    $soundPath = 'menu-option/'.$tenant->id.'/'.str_repeat('d', 64).'.wav';
    $menu = menuFor($tenant, [menuKey('2', MenuAction::HearMessage, 'Opening hours', $soundPath)]);
    fakeAgentRouter(null);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('play')->twice()->andReturn('play-1', 'play-2');
    $telephony->shouldReceive('stopPlayback')->once();

    $flow = callerAtMenu($tenant, $menu, $telephony);
    $flow->handle(dtmfReceived('2', 'caller-leg'));
    $flow->handle(stasisEnd('caller-leg'));

    expect(TenantContext::cross(fn () => Call::query()->count()))->toBe(1);
});

it('leaves a number with no menu working exactly as it did before (AU-18)', function () {
    $tenant = openClient();
    fakeNumberDirectory(null);   // no menu on this number
    fakeAgentRouter(6);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('startHoldMusic')->once();
    $telephony->shouldReceive('placeCall')->once()->andReturn('agent-leg');
    // A strict mock: any play at all would fail this test.

    $flow = new CallToAgentFlow($telephony, new Switchboard($telephony));
    $flow->handle(stasisStart('caller-leg', [], '9998887777', (string) $tenant->id));

    expect($flow->state())->toBe(CallFlowState::RingingAgent);
});

it('never reaches the menu while the client is closed (AU-21)', function () {
    $week = array_fill_keys(
        ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
        null,   // closed all week
    );
    $tenant = Tenant::factory()->create([
        'settings' => ['hours' => $week, 'closed_hours' => ClosedHours::NoPickup->value],
    ]);
    $menu = menuFor($tenant, [menuKey('1', MenuAction::TalkToAgent, 'Sales')]);
    fakeNumberDirectory($menu->id);
    fakeAgentRouter(6);

    // A strict mock: answering or playing the greeting would each fail the test. Closed
    // means closed, and the hours are read before anything is picked up.
    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('hangup')->once()->with('caller-leg', 'busy');

    $flow = new CallToAgentFlow($telephony, new Switchboard($telephony));
    $flow->handle(stasisStart('caller-leg', [], '9998887777', (string) $tenant->id));

    $call = TenantContext::cross(fn () => Call::query()->sole());
    expect($call->missed_reason)->toBe(MissedReason::ClosedHours);
});

it('falls through to today\'s behaviour when the menu has lost its greeting', function () {
    // The edit form refuses to save a menu without a greeting, so this is the net for one
    // that goes missing later. Playing silence and then putting the caller through reads
    // as a dropped call; going straight to a desk is the ending that is always safe.
    $tenant = openClient();
    $menu = TenantContext::run($tenant->id, fn (): Menu => Menu::factory()->create([
        'options' => [menuKey('1', MenuAction::TalkToAgent, 'Sales')],
    ]));
    fakeNumberDirectory($menu->id);
    fakeAgentRouter(6);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('startHoldMusic')->once();
    $telephony->shouldReceive('placeCall')->once()->andReturn('agent-leg');

    $flow = callerAtMenu($tenant, $menu, $telephony);

    expect($flow->state())->toBe(CallFlowState::RingingAgent);
});

it('gives up on a caller stranded at the menu once the client\'s maximum hold runs out', function () {
    // 🔴 Ankit's call, S169. The menu times itself by its own sounds, so the sweep would
    // otherwise never look at this caller — and a "sound finished" event that never
    // arrives would leave them on a live line with no ending at all.
    $tenant = openClient();
    $tenant->update(['max_hold_seconds' => 30]);
    $menu = menuFor($tenant, [menuKey('1', MenuAction::TalkToAgent, 'Sales')]);
    fakeAgentRouter(null);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('play')->once()->andReturn('play-1');

    $flow = callerAtMenu($tenant, $menu, $telephony);

    $flow->tryAgain();
    expect($flow->state())->toBe(CallFlowState::InMenu);   // still inside the cap

    $telephony->shouldReceive('hangup')->once();
    $this->travel(31)->seconds();
    $flow->tryAgain();

    expect($flow->state())->toBe(CallFlowState::Idle);
    expect(TenantContext::cross(fn () => Call::query()->count()))->toBe(1);
});

it('counts a caller at the menu in no column on the wall board (Ankit, S169)', function () {
    $tenant = openClient();
    $menu = menuFor($tenant, [menuKey('1', MenuAction::TalkToAgent, 'Sales')]);
    fakeAgentRouter(null);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('play')->once()->andReturn('play-1');

    fakeNumberDirectory($menu->id);
    $switchboard = new Switchboard($telephony);
    $switchboard->handle(stasisStart('caller-leg', [], '9998887777', (string) $tenant->id));

    // 🔴 The tally's match is deliberately exhaustive. A new state without a home there
    // throws on every publish, and rescue() swallows it into a log while the whole board
    // silently stops updating — so this proves both the arm AND that nothing throws.
    expect($switchboard->tallyByTenant())->toBe([]);
});

it('files the hang-up when the engine reports the sound first, as a real one does (S170)', function () {
    $tenant = openClient();
    $menu = menuFor($tenant, [menuKey('1', MenuAction::TalkToAgent, 'Sales')]);
    fakeAgentRouter(null);

    // 🔴 THE REAL ORDER, WHICH THE TIDY ONE ABOVE MISSES. A dropped line ends the play,
    // and that report arrives BEFORE the leg-ended event — so the miss path asks for the
    // replay and the engine answers "no such channel". Staging, S170: the caller was
    // filed under the general abort's reason instead of this one.
    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('play')->once()->andReturn('play-1');
    $telephony->shouldReceive('play')->once()->andThrow(new AriNotFound('HTTP 404: Channel not found'));

    $flow = callerAtMenu($tenant, $menu, $telephony);
    $flow->handle(playbackFinished('play-1', 'caller-leg'));

    $call = TenantContext::cross(fn () => Call::query()->sole());

    expect($call->outcome)->toBe(CallOutcome::Abandoned)
        ->and($call->missed_reason)->toBe(MissedReason::HungUpInMenu);
});

/*
| inbound-audio slice 7 — "ring a department". The flow decides WHEN a caller may widen
| (the client's wait from the key press, or nobody in the department logged in); the
| router, proven in AgentRouterTest, decides WHO. So these read what each try asked for.
*/

/** A department at $tenant with these members, each given a phone. */
function departmentFor(Tenant $tenant, User ...$members): Department
{
    return TenantContext::run($tenant->id, function () use ($members): Department {
        $department = Department::factory()->create();
        $department->members()->attach(array_map(fn (User $member): int => $member->id, $members));

        return $department;
    });
}

/** A member at work: a phone and a fresh board row in this status. */
function memberAtWork(Tenant $tenant, PresenceStatus $status): User
{
    $member = clientUserWithRole($tenant, 'agent');
    $member->forceFill(['sip_extension' => (string) (1100 + $member->id)])->save();
    TenantContext::run($tenant->id, fn () => AgentPresence::factory()->forUser($member)->status($status)->create());

    return $member;
}

function departmentKey(string $key, Department $department): array
{
    return [...menuKey($key, MenuAction::RingDepartment, $department->name), 'department_id' => $department->id];
}

/** A telephony double that lets a caller reach the menu, press a key and hold. */
function menuPhone(): TelephonyProvider
{
    $telephony = Mockery::mock(TelephonyProvider::class)->shouldIgnoreMissing();
    $telephony->shouldReceive('play')->andReturn('play-1', 'play-2', 'play-3', 'play-4');

    return $telephony;
}

it('asks the department first, without widening, while a member is at work (D3)', function () {
    $tenant = openClient();
    $department = departmentFor($tenant, memberAtWork($tenant, PresenceStatus::OnBreak));
    $router = fakeAgentRouter(null);

    $flow = callerAtMenu($tenant, menuFor($tenant, [departmentKey('2', $department)]), menuPhone());
    $flow->handle(dtmfReceived('2', 'caller-leg'));

    expect($flow->state())->toBe(CallFlowState::Waiting)
        ->and($router->asked)->toBe([[$department->id, false]]);
});

it('widens at the client\'s wait, counted from the key press and not from arrival (D3, D5)', function () {
    $tenant = openClient();
    $tenant->update(['department_wait_seconds' => 30]);
    $department = departmentFor($tenant, memberAtWork($tenant, PresenceStatus::OnBreak));
    $router = fakeAgentRouter(null);

    $flow = callerAtMenu($tenant, menuFor($tenant, [departmentKey('2', $department)]), menuPhone());
    $this->travel(20)->seconds();                 // twenty seconds listening to the menu
    $flow->handle(dtmfReceived('2', 'caller-leg'));

    $this->travel(29)->seconds();                 // 49 since arrival, 29 since the key
    $flow->tryAgain();
    $this->travel(1)->seconds();                  // 30 since the key
    $flow->tryAgain();

    expect($router->asked)->toBe([
        [$department->id, false],
        [$department->id, false],
        [$department->id, true],
    ]);
});

it('widens at once when nobody in the department is logged in, or it has nobody', function (bool $withOfflineMember) {
    $tenant = openClient();
    $department = $withOfflineMember
        ? departmentFor($tenant, memberAtWork($tenant, PresenceStatus::Offline))
        : departmentFor($tenant);
    $router = fakeAgentRouter(null);

    $flow = callerAtMenu($tenant, menuFor($tenant, [departmentKey('2', $department)]), menuPhone());
    $flow->handle(dtmfReceived('2', 'caller-leg'));

    expect($router->asked)->toBe([[$department->id, true]]);
})->with(['everyone offline' => true, 'an empty department' => false]);

it('widens on the next try when the last member logs out mid-wait (D4)', function () {
    $tenant = openClient();
    $member = memberAtWork($tenant, PresenceStatus::OnBreak);
    $department = departmentFor($tenant, $member);
    $router = fakeAgentRouter(null);

    $flow = callerAtMenu($tenant, menuFor($tenant, [departmentKey('2', $department)]), menuPhone());
    $flow->handle(dtmfReceived('2', 'caller-leg'));

    TenantContext::run($tenant->id, fn () => AgentPresence::query()->where('user_id', $member->id)->update(['status' => PresenceStatus::Offline->value]));
    $flow->tryAgain();

    expect($router->asked)->toBe([[$department->id, false], [$department->id, true]]);
});

it('tries two waiting callers for different departments in arrival order (D5)', function () {
    $tenant = openClient();
    $sales = departmentFor($tenant, memberAtWork($tenant, PresenceStatus::OnBreak));
    $hindi = departmentFor($tenant, memberAtWork($tenant, PresenceStatus::OnBreak));
    $menu = menuFor($tenant, [departmentKey('1', $sales), departmentKey('2', $hindi)]);
    fakeNumberDirectory($menu->id);
    $router = fakeAgentRouter(null);

    $switchboard = new Switchboard(menuPhone());
    $switchboard->handle(stasisStart('caller-A', [], '9998887777', (string) $tenant->id));
    $switchboard->handle(stasisStart('caller-B', [], '9998887778', (string) $tenant->id));
    // B presses first; A still arrived first, so A is tried first on every sweep.
    $switchboard->handle(dtmfReceived('2', 'caller-B'));
    $switchboard->handle(dtmfReceived('1', 'caller-A'));
    $router->asked = [];

    $switchboard->sweepWaiting();

    expect($router->asked)->toBe([[$sales->id, false], [$hindi->id, false]]);
});

it('records whether the reserved agent came from outside the department', function (bool $member) {
    $tenant = openClient();
    $agent = memberAtWork($tenant, PresenceStatus::Ready);
    $department = $member ? departmentFor($tenant, $agent) : departmentFor($tenant);
    fakeAgentRouter($agent->id);

    $flow = callerAtMenu($tenant, menuFor($tenant, [departmentKey('2', $department)]), menuPhone());
    $flow->handle(dtmfReceived('2', 'caller-leg'));

    expect($flow->state())->toBe(CallFlowState::RingingAgent)
        ->and((fn () => $this->departmentWidened)->call($flow))->toBe(! $member);
})->with(['a member' => true, 'an outsider' => false]);

// D8 — every other way to a desk asks with no department, exactly as before.

it('asks with no department on every other way to a desk (D8)', function (string $how) {
    $tenant = openClient();
    $router = fakeAgentRouter(null);

    if ($how === 'no menu') {
        fakeNumberDirectory(null);
        (new CallToAgentFlow(menuPhone(), new Switchboard(menuPhone())))
            ->handle(stasisStart('caller-leg', [], '9998887777', (string) $tenant->id));
    } else {
        $flow = callerAtMenu($tenant, menuFor($tenant, [menuKey('1', MenuAction::TalkToAgent, 'Sales')]), menuPhone());
        $presses = $how === 'talk to an agent' ? ['1'] : ['7', '7'];   // 7 is not on this menu

        foreach ($presses as $press) {
            $flow->handle(dtmfReceived($press, 'caller-leg'));
        }
    }

    expect($router->asked)->toBe([[null, false]]);
})->with(['no menu', 'talk to an agent', 'no choice made']);
