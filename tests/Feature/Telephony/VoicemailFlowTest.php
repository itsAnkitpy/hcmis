<?php

use App\Enums\CallOutcome;
use App\Enums\ClosedHours;
use App\Enums\MenuAction;
use App\Enums\MissedReason;
use App\Events\Telephony\RecordingReady;
use App\Jobs\StoreVoicemailJob;
use App\Listeners\AttachRecordingToCall;
use App\Models\Call;
use App\Models\Menu;
use App\Models\Tenant;
use App\Telephony\AriNotFound;
use App\Telephony\Flows\CallFlowState;
use App\Telephony\Flows\CallToAgentFlow;
use App\Telephony\Flows\Switchboard;
use App\Telephony\TelephonyProvider;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

/**
 * inbound-audio.md slice 8, step 2 — the three ways in to the message pad (AU-29) and
 * the greeting → beep → record order (AU-30). The recording's FINISH is the
 * switchboard's and is tested with it.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    fakeNumberDirectory();
    fakeAgentDirectory();
    Queue::fake();
});

afterEach(fn () => TenantContext::forget());

/** Switch voicemail on with a converted greeting. */
function takingMessages(Tenant $tenant): Tenant
{
    $tenant->update([
        'voicemail_enabled' => true,
        'voicemail_greeting_path' => 'voicemail-greeting/'.$tenant->id.'/'.str_repeat('d', 64).'.wav',
    ]);

    return $tenant->fresh();
}

/** A client closed all week, answering with a closed message or not picking up. */
function closedAllWeek(ClosedHours $closedHours): Tenant
{
    $tenant = Tenant::factory()->create([
        'settings' => [
            'hours' => array_fill_keys(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], null),
            'closed_hours' => $closedHours->value,
        ],
        'closed_message_path' => 'closed-message/1/'.str_repeat('a', 64).'.wav',
    ]);

    return $tenant->fresh();
}

function onlyMissedRow(): Call
{
    return TenantContext::cross(fn () => Call::query()->sole());
}

// --- after the closed message ---

it('plays the greeting after the closed message, then records after it (AU-29, AU-30)', function () {
    $tenant = takingMessages(closedAllWeek(ClosedHours::Message));
    fakeAgentRouter(null);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('play')->once()->with('caller-leg', [$tenant->closedMessageUrl()])->andReturn('closed-play');
    $telephony->shouldReceive('play')->once()->with('caller-leg', [$tenant->voicemailGreetingUrl()])->andReturn('greeting-play');
    $recorded = null;
    $telephony->shouldReceive('recordMessage')->once()
        ->with('caller-leg', Mockery::capture($recorded));
    $telephony->shouldNotReceive('hangup');

    $switchboard = new Switchboard($telephony);
    $switchboard->handle(stasisStart('caller-leg', [], '9998887777', (string) $tenant->id));
    $switchboard->handle(playbackFinished('closed-play', 'caller-leg'));
    $switchboard->handle(playbackFinished('greeting-play', 'caller-leg'));

    // The recording is named after the ticket, which is what the missed row carries —
    // so the file can find its row.
    $call = onlyMissedRow();

    expect($recorded)->toBe('voicemail-'.$call->correlation_id)
        ->and($call->missed_reason)->toBe(MissedReason::ClosedHours)
        ->and($switchboard->activeCallCount())->toBe(1);
});

it('never offers a message when closed means "no pick-up" (AU-29)', function () {
    $tenant = takingMessages(closedAllWeek(ClosedHours::NoPickup));
    fakeAgentRouter(null);

    // Strict: answering or playing anything would fail the test.
    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('hangup')->once()->with('caller-leg', 'busy');

    (new CallToAgentFlow($telephony, new Switchboard($telephony)))
        ->handle(stasisStart('caller-leg', [], '9998887777', (string) $tenant->id));

    expect(onlyMissedRow()->missed_reason)->toBe(MissedReason::ClosedHours);
});

it('keeps today\'s ending after the closed message when voicemail is off', function () {
    $tenant = closedAllWeek(ClosedHours::Message);
    fakeAgentRouter(null);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('play')->once()->andReturn('closed-play');
    $telephony->shouldReceive('hangup')->once()->with('caller-leg');

    $switchboard = new Switchboard($telephony);
    $switchboard->handle(stasisStart('caller-leg', [], '9998887777', (string) $tenant->id));
    $switchboard->handle(playbackFinished('closed-play', 'caller-leg'));

    expect($switchboard->activeCallCount())->toBe(0);
});

// --- at the hold limit ---

it('sends a waiting caller to the message pad at the hold limit instead of hanging up (AU-29)', function () {
    $tenant = takingMessages(Tenant::factory()->create(['max_hold_seconds' => 60]));
    fakeAgentRouter(null);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('startHoldMusic')->once();
    // The music is stopped outright, so nothing of the waiting area sounds over the message.
    $telephony->shouldReceive('stopHoldMusic')->once()->with('caller-leg');
    $telephony->shouldReceive('play')->once()->with('caller-leg', [$tenant->voicemailGreetingUrl()])->andReturn('greeting-play');
    $telephony->shouldNotReceive('hangup');

    $flow = new CallToAgentFlow($telephony, new Switchboard($telephony));
    $flow->handle(stasisStart('caller-leg', [], '9998887777', (string) $tenant->id));

    $this->travel(61)->seconds();
    $flow->tryAgain();

    $call = onlyMissedRow();

    expect($flow->state())->toBe(CallFlowState::LeavingMessage)
        ->and($call->outcome)->toBe(CallOutcome::NoAnswer)
        ->and($call->missed_reason)->toBeNull();
});

it('drops only the desk still ringing when the hold limit sends the caller to the message pad', function () {
    $tenant = takingMessages(Tenant::factory()->create(['max_hold_seconds' => 60]));
    fakeAgentRouter(clientUserWithRole($tenant, 'agent')->id);

    $telephony = fakeTelephony();
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('placeCall')->once()->andReturn('agent-leg');
    $telephony->shouldReceive('hangup')->once()->with('agent-leg');
    $telephony->shouldReceive('play')->once()->andReturn('greeting-play');

    $flow = new CallToAgentFlow($telephony, new Switchboard($telephony));
    $flow->handle(stasisStart('caller-leg', [], '9998887777', (string) $tenant->id));
    expect($flow->state())->toBe(CallFlowState::RingingAgent);

    $this->travel(61)->seconds();
    $flow->tryAgain();

    // The dropped desk's own end arrives late (review S175, handoff question 2). It must
    // not read as a ring-out: no waiting room, no music, the caller stays at the pad.
    $flow->handle(channelDestroyed('agent-leg'));

    expect($flow->state())->toBe(CallFlowState::LeavingMessage)
        ->and(onlyMissedRow()->outcome)->toBe(CallOutcome::NoAnswer);
    $telephony->shouldHaveReceived('startHoldMusic')->once();   // the ring's own, never restarted
});

it('stops the waiting announcement for the greeting, and ignores its late finish (review S175, handoff question 3)', function () {
    $tenant = takingMessages(Tenant::factory()->create([
        'max_hold_seconds' => 90,
        'waiting_message_path' => 'waiting-message/1/'.str_repeat('b', 64).'.wav',
    ]));
    fakeAgentRouter(null);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('startHoldMusic')->once();   // arrival only, never restarted
    $telephony->shouldReceive('play')->once()->with('caller-leg', [$tenant->waitingMessageUrl()])->andReturn('announce-play');
    $telephony->shouldReceive('stopPlayback')->once()->with('announce-play');
    // The announcement already switched the music off (main/file.c), so there is none to stop.
    $telephony->shouldNotReceive('stopHoldMusic');
    $telephony->shouldReceive('play')->once()->with('caller-leg', [$tenant->voicemailGreetingUrl()])->andReturn('greeting-play');
    $telephony->shouldNotReceive('recordMessage');
    $telephony->shouldNotReceive('hangup');

    $flow = new CallToAgentFlow($telephony, new Switchboard($telephony));
    $flow->handle(stasisStart('caller-leg', [], '9998887777', (string) $tenant->id));

    $this->travel(60)->seconds();
    $flow->tryAgain();                                      // the announcement starts

    $this->travel(31)->seconds();
    $flow->tryAgain();                                      // the hold limit, mid-announcement

    $flow->handle(playbackFinished('announce-play', 'caller-leg'));

    expect($flow->state())->toBe(CallFlowState::LeavingMessage);
});

// --- from the menu ---

/** A menu on an always-open client with the voicemail key on 3. */
function menuWithVoicemailKey(Tenant $tenant): Menu
{
    $tenant->update(['settings' => ['closed_hours' => ClosedHours::Off->value]]);

    $menu = TenantContext::run($tenant->id, fn (): Menu => Menu::factory()
        ->withGreeting('menu-greeting/'.$tenant->id.'/'.str_repeat('c', 64).'.wav')
        ->create(['options' => [[
            'key' => '3',
            'label' => 'Leave a message',
            'action' => MenuAction::LeaveMessage->value,
            'sound_path' => null,
            'sound_rights_confirmed' => false,
        ]]]));

    fakeNumberDirectory($menu->id);

    return $menu;
}

it('files the voicemail key on Missed Calls and plays the greeting (AU-29, AU-32)', function () {
    $tenant = takingMessages(Tenant::factory()->create());
    menuWithVoicemailKey($tenant);
    fakeAgentRouter(6);   // a free agent must not be rung — the caller asked for the pad

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('play')->once()->andReturn('menu-play');
    $telephony->shouldReceive('stopPlayback')->once();
    $telephony->shouldReceive('play')->once()->with('caller-leg', [$tenant->voicemailGreetingUrl()])->andReturn('greeting-play');

    $flow = new CallToAgentFlow($telephony, new Switchboard($telephony));
    $flow->handle(stasisStart('caller-leg', [], '9998887777', (string) $tenant->id));
    $flow->handle(dtmfReceived('3', 'caller-leg'));

    $call = onlyMissedRow();

    expect($flow->state())->toBe(CallFlowState::LeavingMessage)
        ->and($call->missed_reason)->toBe(MissedReason::AskedForVoicemail)
        ->and($call->missed_reason->showsOnMissedCalls())->toBeTrue()
        ->and($call->menu_choice)->toBe('Leave a message');
});

it('sends the voicemail key to a desk when the client takes no messages', function () {
    $tenant = Tenant::factory()->create();
    menuWithVoicemailKey($tenant);
    fakeAgentRouter(null);   // nobody free — the caller waits, as "talk to an agent" would

    $telephony = fakeTelephony();
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('play')->once()->andReturn('menu-play');
    $telephony->shouldReceive('stopPlayback')->once();

    $flow = new CallToAgentFlow($telephony, new Switchboard($telephony));
    $flow->handle(stasisStart('caller-leg', [], '9998887777', (string) $tenant->id));
    $flow->handle(dtmfReceived('3', 'caller-leg'));

    expect($flow->state())->toBe(CallFlowState::Waiting)
        ->and(TenantContext::cross(fn () => Call::query()->count()))->toBe(0);
});

it('silences the menu when the hold limit sends a caller still in it to the message pad', function () {
    // Review S175 F1. The engine queues a new sound behind one still playing
    // (res_stasis_playback.c: every playback starts QUEUED), so an unstopped menu would
    // keep reading its keys to a caller whose presses are no longer heard.
    $tenant = takingMessages(Tenant::factory()->create(['max_hold_seconds' => 30]));
    menuWithVoicemailKey($tenant);
    fakeAgentRouter(null);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('play')->once()->andReturn('menu-play');
    $telephony->shouldReceive('stopPlayback')->once()->with('menu-play');
    $telephony->shouldReceive('play')->once()->with('caller-leg', [$tenant->voicemailGreetingUrl()])->andReturn('greeting-play');
    $telephony->shouldNotReceive('hangup');
    $telephony->shouldNotReceive('recordMessage');

    $flow = new CallToAgentFlow($telephony, new Switchboard($telephony));
    $flow->handle(stasisStart('caller-leg', [], '9998887777', (string) $tenant->id));
    expect($flow->state())->toBe(CallFlowState::InMenu);

    $this->travel(31)->seconds();
    $flow->tryAgain();

    // The stopped menu's late report matches nothing: no beep, no recording.
    $flow->handle(playbackFinished('menu-play', 'caller-leg'));

    expect($flow->state())->toBe(CallFlowState::LeavingMessage)
        ->and(onlyMissedRow()->outcome)->toBe(CallOutcome::NoAnswer);
});

// --- endings at the pad ---

/** A caller at the message pad via the closed message, greeting playing. */
function callerAtThePad(TelephonyProvider $telephony, Tenant $tenant): Switchboard
{
    $switchboard = new Switchboard($telephony);
    $switchboard->handle(stasisStart('caller-leg', [], '9998887777', (string) $tenant->id));
    $switchboard->handle(playbackFinished('closed-play', 'caller-leg'));

    return $switchboard;
}

function padPhone(): TelephonyProvider
{
    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('play')->once()->andReturn('closed-play');
    $telephony->shouldReceive('play')->once()->andReturn('greeting-play');

    return $telephony;
}

it('ends quietly when the caller hangs up mid-greeting and the engine reports the sound first (S170)', function () {
    $tenant = takingMessages(closedAllWeek(ClosedHours::Message));
    fakeAgentRouter(null);

    $telephony = padPhone();
    $telephony->shouldReceive('recordMessage')->once()->andThrow(new AriNotFound('HTTP 404: Channel not found'));
    $telephony->shouldReceive('hangup')->once()->with('caller-leg');

    $switchboard = callerAtThePad($telephony, $tenant);
    $switchboard->handle(playbackFinished('greeting-play', 'caller-leg'));
    $switchboard->handle(stasisEnd('caller-leg'));

    expect($switchboard->activeCallCount())->toBe(0)
        ->and(TenantContext::cross(fn () => Call::query()->count()))->toBe(1);
});

it('writes no second row when the caller hangs up mid-message', function () {
    $tenant = takingMessages(closedAllWeek(ClosedHours::Message));
    fakeAgentRouter(null);

    $telephony = padPhone();
    $telephony->shouldReceive('recordMessage')->once();
    $telephony->shouldNotReceive('hangup');

    $switchboard = callerAtThePad($telephony, $tenant);
    $switchboard->handle(playbackFinished('greeting-play', 'caller-leg'));
    $switchboard->handle(stasisEnd('caller-leg'));

    expect($switchboard->activeCallCount())->toBe(0)
        ->and(TenantContext::cross(fn () => Call::query()->count()))->toBe(1);
});

it('counts a caller at the message pad in no column on the wall board', function () {
    $tenant = takingMessages(closedAllWeek(ClosedHours::Message));
    fakeAgentRouter(null);

    $switchboard = callerAtThePad(padPhone(), $tenant);

    expect($switchboard->tallyByTenant())->toBe([]);
});

it('drops a caller left at the pad past its limit, as a net under a lost finish event', function () {
    $tenant = takingMessages(closedAllWeek(ClosedHours::Message));
    fakeAgentRouter(null);

    $telephony = padPhone();
    $telephony->shouldReceive('recordMessage')->once();

    $switchboard = callerAtThePad($telephony, $tenant);
    $switchboard->handle(playbackFinished('greeting-play', 'caller-leg'));

    $this->travel(299)->seconds();
    $switchboard->sweepWaiting();
    expect($switchboard->activeCallCount())->toBe(1);

    $telephony->shouldReceive('hangup')->once()->with('caller-leg');
    $this->travel(2)->seconds();
    $switchboard->sweepWaiting();

    expect($switchboard->activeCallCount())->toBe(0)
        ->and(TenantContext::cross(fn () => Call::query()->count()))->toBe(1);
});

it('gives the message its full limit after a long greeting (review S175 F2)', function () {
    // The pad's clock restarts at the beep. Counted from the greeting, a client's
    // three-minute greeting left the caller two of their three minutes.
    $tenant = takingMessages(closedAllWeek(ClosedHours::Message));
    fakeAgentRouter(null);

    $telephony = padPhone();
    $telephony->shouldReceive('recordMessage')->once();
    $telephony->shouldNotReceive('hangup');

    $switchboard = callerAtThePad($telephony, $tenant);
    $this->travel(180)->seconds();                 // a three-minute greeting
    $switchboard->handle(playbackFinished('greeting-play', 'caller-leg'));

    $this->travel(240)->seconds();                 // 420 since the pad opened, 240 since the beep
    $switchboard->sweepWaiting();

    expect($switchboard->activeCallCount())->toBe(1);
});

it('still drops a caller whose greeting never reports finishing', function () {
    $tenant = takingMessages(closedAllWeek(ClosedHours::Message));
    fakeAgentRouter(null);

    $telephony = padPhone();
    $telephony->shouldNotReceive('recordMessage');

    $switchboard = callerAtThePad($telephony, $tenant);

    $telephony->shouldReceive('hangup')->once()->with('caller-leg');
    $this->travel(301)->seconds();
    $switchboard->sweepWaiting();

    expect($switchboard->activeCallCount())->toBe(0);
});

// --- step 3: the switchboard finishes the message (AU-30, AU-31) ---

/** The engine's finish for a message, in the 20 branch's LiveRecording shape. */
function messageFinished(string $name, ?int $spokeSeconds): array
{
    return ['type' => 'RecordingFinished', 'recording' => array_filter([
        'name' => $name,
        'target_uri' => 'channel:caller-leg',
        'state' => 'done',
        'duration' => 20,
        'talking_duration' => $spokeSeconds,
    ], fn (mixed $value): bool => $value !== null)];
}

/** A caller mid-message: the pad opened, the greeting finished, the recording started. */
function callerRecording(TelephonyProvider $telephony, Tenant $tenant): Switchboard
{
    $switchboard = callerAtThePad($telephony, $tenant);
    $switchboard->handle(playbackFinished('greeting-play', 'caller-leg'));

    return $switchboard;
}

function messageName(): string
{
    return 'voicemail-'.onlyMissedRow()->correlation_id;
}

it('keeps a message with enough speech, and ends the call of a caller still on the line', function () {
    $tenant = takingMessages(closedAllWeek(ClosedHours::Message));
    fakeAgentRouter(null);

    $telephony = padPhone();
    $telephony->shouldReceive('recordMessage')->once();
    // Stopped by silence or # — the caller is still there, so we end it.
    $telephony->shouldReceive('hangup')->once()->with('caller-leg');

    $switchboard = callerRecording($telephony, $tenant);
    $switchboard->handle(messageFinished(messageName(), 12));

    Queue::assertPushed(StoreVoicemailJob::class, fn (StoreVoicemailJob $job): bool => $job->callId === onlyMissedRow()->correlation_id
        && $job->recordingName === messageName());
});

it('drops a message under three seconds of speech, and the caller stays on Missed Calls (AU-31)', function (int $spokeSeconds) {
    $tenant = takingMessages(closedAllWeek(ClosedHours::Message));
    fakeAgentRouter(null);

    $telephony = padPhone();
    $telephony->shouldReceive('recordMessage')->once();
    $telephony->shouldReceive('hangup')->once()->with('caller-leg');

    $switchboard = callerRecording($telephony, $tenant);
    $switchboard->handle(messageFinished(messageName(), $spokeSeconds));

    Queue::assertNotPushed(StoreVoicemailJob::class);
    expect(onlyMissedRow()->recording_path)->toBeNull();
})->with([
    'silent (the lab\'s empty recording read about 1 s)' => 1,
    'just short' => 2,
]);

it('keeps a message of exactly three seconds of speech', function () {
    $tenant = takingMessages(closedAllWeek(ClosedHours::Message));
    fakeAgentRouter(null);

    $telephony = padPhone();
    $telephony->shouldReceive('recordMessage')->once();
    $telephony->shouldReceive('hangup')->once();

    $switchboard = callerRecording($telephony, $tenant);
    $switchboard->handle(messageFinished(messageName(), 3));

    Queue::assertPushed(StoreVoicemailJob::class);
});

it('keeps the message when the engine leaves out the seconds of speech', function () {
    $tenant = takingMessages(closedAllWeek(ClosedHours::Message));
    fakeAgentRouter(null);

    $telephony = padPhone();
    $telephony->shouldReceive('recordMessage')->once();
    $telephony->shouldReceive('hangup')->once();

    $switchboard = callerRecording($telephony, $tenant);
    $switchboard->handle(messageFinished(messageName(), null));

    Queue::assertPushed(StoreVoicemailJob::class);
});

it('still keeps the message of a caller who hung up mid-message, after their call is gone (FD-4)', function () {
    // The commonest ending: the caller says their piece and hangs up. The call is
    // forgotten before the engine reports the file.
    $tenant = takingMessages(closedAllWeek(ClosedHours::Message));
    fakeAgentRouter(null);

    $telephony = padPhone();
    $telephony->shouldReceive('recordMessage')->once();
    $telephony->shouldNotReceive('hangup');

    $switchboard = callerRecording($telephony, $tenant);
    $name = messageName();
    $switchboard->handle(stasisEnd('caller-leg'));
    $switchboard->handle(messageFinished($name, 9));

    expect($switchboard->activeCallCount())->toBe(0);
    Queue::assertPushed(StoreVoicemailJob::class);
});

it('ends the call when the engine refuses the message, keeping nothing', function () {
    $tenant = takingMessages(closedAllWeek(ClosedHours::Message));
    fakeAgentRouter(null);

    $telephony = padPhone();
    $telephony->shouldReceive('recordMessage')->once();
    $telephony->shouldReceive('hangup')->once()->with('caller-leg');

    $switchboard = callerRecording($telephony, $tenant);
    $switchboard->handle(['type' => 'RecordingFailed', 'recording' => [
        'name' => messageName(),
        'target_uri' => 'channel:caller-leg',
        'state' => 'failed',
        'cause' => 'Cannot record channel while in bridge',
    ]]);

    Queue::assertNotPushed(StoreVoicemailJob::class);
});

it('handles a message\'s finish once, whatever arrives after it', function () {
    $tenant = takingMessages(closedAllWeek(ClosedHours::Message));
    fakeAgentRouter(null);

    $telephony = padPhone();
    $telephony->shouldReceive('recordMessage')->once();
    $telephony->shouldReceive('hangup')->once();

    $switchboard = callerRecording($telephony, $tenant);
    $name = messageName();
    $switchboard->handle(messageFinished($name, 9));
    $switchboard->handle(messageFinished($name, 9));

    Queue::assertPushed(StoreVoicemailJob::class, 1);
});

it('lands the stored message on the caller\'s missed row through the call recordings\' own attach (S174 decision 2)', function () {
    $tenant = takingMessages(closedAllWeek(ClosedHours::Message));
    fakeAgentRouter(null);

    $telephony = padPhone();
    $telephony->shouldReceive('recordMessage')->once();
    callerAtThePad($telephony, $tenant)->handle(playbackFinished('greeting-play', 'caller-leg'));

    $row = onlyMissedRow();
    (new AttachRecordingToCall)->handle(new RecordingReady(
        $row->correlation_id,
        'voicemail-'.$row->correlation_id,
        'local',
        'recordings/voicemail-'.$row->correlation_id.'.mp3',
    ));

    expect(onlyMissedRow()->recording_path)->toBe('recordings/voicemail-'.$row->correlation_id.'.mp3')
        ->and(onlyMissedRow()->recording_disk)->toBe('local');
});
