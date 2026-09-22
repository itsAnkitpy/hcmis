<?php

use App\Enums\CallOutcome;
use App\Enums\ClosedHours;
use App\Enums\DncSource;
use App\Enums\MenuAction;
use App\Enums\MissedReason;
use App\Models\Call;
use App\Models\CallHandoff;
use App\Models\DncEntry;
use App\Models\Menu;
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
