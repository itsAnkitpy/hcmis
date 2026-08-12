<?php

use App\Enums\CallDirection;
use App\Enums\RoleName;
use App\Filament\Pages\AgentConsole;
use App\Models\Call;
use App\Models\CallHandoff;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Call timing, the console's half (PRD/phase-2/call-timing.md CT-2/CT-6/CT-11).
 *
 * The listener stamps its four moments onto the handoff note; the wrap-up copies them
 * onto the calls row, matched on the ticket this console is holding. The listener's
 * half lives in tests/Feature/Telephony/CallTimingTest.php.
 */
uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::forget();
});

/**
 * An inbound call already claimed by this console: a note carrying the given moments,
 * and a console holding that same ticket (what claimHandoffTicket() leaves behind at
 * ring-time). Returns the console, ready for its wrap-up.
 *
 * @param  array<string, mixed>  $moments
 */
function consoleHoldingNote(Tenant $tenant, User $agent, array $moments, ?string $ticket = null): AgentConsole
{
    $ticket ??= (string) Str::uuid();

    TenantContext::run($tenant->id, fn () => CallHandoff::factory()
        ->forAgent($agent)
        ->ticket($ticket)
        ->create($moments));

    $page = new AgentConsole;
    $page->callCorrelationId = $ticket;
    $page->callPartyNumber = '9991234567';

    return $page;
}

/** The one row this client has. */
function onlyCall(Tenant $tenant): ?Call
{
    return TenantContext::run($tenant->id, fn (): ?Call => Call::query()->latest('id')->first());
}

beforeEach(function () {
    Http::fake(['*' => Http::response(['id' => 'agent-leg'])]);
});

it('copies all five moments onto the row at Done, in order (CT-2)', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $this->actingAs($agent);

    $arrived = now()->subMinutes(5);
    $answered = now()->subMinutes(4);
    $ended = now()->subMinute();

    $page = consoleHoldingNote($tenant, $agent, [
        'arrived_at' => $arrived,
        'answered_at' => $answered,
        'ended_at' => $ended,
    ]);

    TenantContext::run($tenant->id, fn () => $page->completeUnmatched());

    $call = onlyCall($tenant);

    expect($call)->not->toBeNull()
        ->and($call->started_at->timestamp)->toBe($arrived->timestamp)
        ->and($call->answered_at->timestamp)->toBe($answered->timestamp)
        ->and($call->ended_at->timestamp)->toBe($ended->timestamp)
        // The ring moment is the note's own created_at, and the Done click is the
        // row's own created_at — two moments we were already storing without noticing.
        ->and($call->ringing_at)->not->toBeNull()
        ->and($call->created_at->greaterThanOrEqualTo($call->ended_at))->toBeTrue();
});

it('stores the hang-up, not the Done click — so talk time excludes wrap-up (CT-6)', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $this->actingAs($agent);

    // The caller hung up ten minutes ago and the agent has been typing notes since.
    $ended = now()->subMinutes(10);

    $page = consoleHoldingNote($tenant, $agent, [
        'arrived_at' => now()->subMinutes(20),
        'answered_at' => now()->subMinutes(18),
        'ended_at' => $ended,
    ]);

    TenantContext::run($tenant->id, fn () => $page->completeUnmatched());

    $call = onlyCall($tenant);

    // Talk time is answered -> ended (8 minutes), NOT answered -> Done (18 minutes).
    expect($call->ended_at->timestamp)->toBe($ended->timestamp)
        ->and((int) $call->answered_at->diffInMinutes($call->ended_at))->toBe(8)
        // Wrap-up time falls out for free: hang-up -> the row's own created_at.
        ->and((int) $call->ended_at->diffInMinutes($call->created_at))->toBe(10);
});

it('writes a blank end rather than falling back to the Done time (CT-6)', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $this->actingAs($agent);

    // Done clicked almost instantly — the hang-up stamp has not landed on the note yet.
    $page = consoleHoldingNote($tenant, $agent, [
        'arrived_at' => now()->subMinute(),
        'answered_at' => now()->subSeconds(30),
        'ended_at' => null,
    ]);

    TenantContext::run($tenant->id, fn () => $page->completeUnmatched());

    // A dash on one row is honest; a plausible wrong number is not (B3 D4). A fallback
    // to now() here would silently reinstate the wrap-up-inflated duration this slice
    // exists to remove.
    expect(onlyCall($tenant)->ended_at)->toBeNull();
});

it('ignores a leftover note from another call — no inbound wait on an outbound row (CT-11)', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $this->actingAs($agent);

    // The story: this agent let their phone ring out. The caller went back to the
    // waiting room and somebody else took them — but the note is still in this agent's
    // pigeonhole, holding that caller's arrival time. Nothing sweeps it until their
    // next inbound ring.
    TenantContext::run($tenant->id, fn () => CallHandoff::factory()
        ->forAgent($agent)
        ->ticket('the-call-they-never-answered')
        ->create([
            'arrived_at' => now()->subMinutes(15),
            'answered_at' => null,
            'ended_at' => now()->subMinutes(14),
        ]));

    // They now dial out. The Dial click mints its own ticket, which matches no note.
    $page = new AgentConsole;
    $page->callCorrelationId = (string) Str::uuid();
    $page->callPartyNumber = '9991234567';
    $page->callDirection = CallDirection::Outbound;

    TenantContext::run($tenant->id, fn () => $page->completeUnmatched());

    $call = onlyCall($tenant);

    // Without the ticket match, a "my newest note" read would have stored a
    // fifteen-minute inbound wait on this outbound call.
    expect($call->started_at)->toBeNull()
        ->and($call->ringing_at)->toBeNull()
        ->and($call->answered_at)->toBeNull()
        ->and($call->ended_at)->toBeNull();
});

it('reads only its OWN note when two agents share a call\'s ticket (CT-11)', function () {
    $tenant = Tenant::factory()->create();
    $priya = clientUserWithRole($tenant, RoleName::Agent->value);
    $rahul = clientUserWithRole($tenant, RoleName::Agent->value);
    $ticket = (string) Str::uuid();

    // One call, two notes — the shape a conference leaves behind (CT-5/CT-13).
    // Priya's part ended when she left; Rahul's ran on to the caller's hang-up.
    $priyaEnded = now()->subMinutes(6);
    $rahulEnded = now()->subMinute();

    TenantContext::run($tenant->id, function () use ($priya, $rahul, $ticket, $priyaEnded, $rahulEnded): void {
        CallHandoff::factory()->forAgent($priya)->ticket($ticket)->create([
            'arrived_at' => now()->subMinutes(12),
            'answered_at' => now()->subMinutes(11),
            'ended_at' => $priyaEnded,
        ]);
        CallHandoff::factory()->forAgent($rahul)->ticket($ticket)->create([
            'arrived_at' => null,
            'answered_at' => now()->subMinutes(7),
            'ended_at' => $rahulEnded,
        ]);
    });

    $this->actingAs($rahul);
    $page = new AgentConsole;
    $page->callCorrelationId = $ticket;
    $page->callPartyNumber = '9991234567';

    TenantContext::run($tenant->id, fn () => $page->completeUnmatched());

    $call = onlyCall($tenant);

    // Rahul's row carries HIS end, not Priya's — and no arrival at all, because when
    // the call reached him the customer was mid-conversation, not in the waiting room.
    expect($call->agent_id)->toBe($rahul->id)
        ->and($call->ended_at->timestamp)->toBe($rahulEnded->timestamp)
        ->and($call->started_at)->toBeNull();
});

it('back-fills the recording from the sibling row when this one is written later (CT-15)', function () {
    $tenant = Tenant::factory()->create();
    $rahul = clientUserWithRole($tenant, RoleName::Agent->value);
    $ticket = (string) Str::uuid();
    $this->actingAs($rahul);

    // The ordinary ordering on every transfer: Priya wrapped up thirty seconds after
    // handing the call over, so HER row exists and the filing job has already attached
    // the audio to it. Rahul talked on, and is only now clicking Done — when the job
    // ran, his row did not exist, and the job never runs again.
    TenantContext::run($tenant->id, fn () => Call::factory()->create([
        'correlation_id' => $ticket,
        'recording_disk' => 'recordings',
        'recording_path' => 'recordings/call-1.mp3',
    ]));

    $page = consoleHoldingNote($tenant, $rahul, ['arrived_at' => null, 'answered_at' => now()->subMinutes(3)], $ticket);
    TenantContext::run($tenant->id, fn () => $page->completeUnmatched());

    $rahulsRow = TenantContext::run(
        $tenant->id,
        fn (): ?Call => Call::query()->where('agent_id', $rahul->id)->latest('id')->first(),
    );

    // Without the back-fill, the second agent's row shows no audio for ever — the very
    // row CT-13 was written to serve.
    expect($rahulsRow->recording_path)->toBe('recordings/call-1.mp3')
        ->and($rahulsRow->recording_disk)->toBe('recordings');
});

it('leaves the recording blank when no sibling row has one (CT-15 — the ordinary single-agent call)', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $this->actingAs($agent);

    $page = consoleHoldingNote($tenant, $agent, ['arrived_at' => now()->subMinutes(2)]);
    TenantContext::run($tenant->id, fn () => $page->completeUnmatched());

    // The normal path is unchanged: the queued job attaches it a moment later.
    expect(onlyCall($tenant)->recording_path)->toBeNull();
});

it('puts talk time on an outbound row, and still no wait (CT-16/CT-8)', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $this->actingAs($agent);

    // The listener's note for an outbound call: a ring (the note's own created_at, when
    // we started ringing the customer), a pickup, a hang-up — and no arrival.
    $page = consoleHoldingNote($tenant, $agent, [
        'arrived_at' => null,
        'answered_at' => now()->subMinutes(7),
        'ended_at' => now()->subMinutes(2),
    ]);
    $page->callDirection = CallDirection::Outbound;

    TenantContext::run($tenant->id, fn () => $page->completeUnmatched());

    $call = onlyCall($tenant);

    expect($call->direction)->toBe(CallDirection::Outbound)
        ->and($call->talkedSeconds())->toBe(300)      // five minutes of conversation
        ->and($call->ringing_at)->not->toBeNull()
        // Nobody waited — we placed the call. The Waited column shows a dash.
        ->and($call->started_at)->toBeNull()
        ->and($call->waitedSeconds())->toBeNull()
        ->and(Call::asClock($call->waitedSeconds()))->toBe('—');
});
