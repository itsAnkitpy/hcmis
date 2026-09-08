<?php

use App\Models\CallHandoff;
use App\Models\Tenant;
use App\Telephony\AgentRouter;
use App\Telephony\Flows\CallToAgentFlow;
use App\Telephony\Flows\Switchboard;
use App\Telephony\RecordingSession;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;

/**
 * Call timing, the operator's half (PRD/phase-2/call-timing.md CT-2/CT-3/CT-11/CT-12a).
 *
 * The listener owns every clock on a call but must not write the calls row (D2's
 * single-writer rule), so it stamps its moments onto the handoff note and the agent's
 * screen copies them across at wrap-up. These tests are about the NOTE — the console
 * half lives in tests/Feature/Filament/AgentConsoleCallTimingTest.php.
 *
 * DB-backed, like the other handoff tests: the write is the thing under test, so it
 * needs a real tenant and a real reserved agent for the FK + RLS write to land.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => fakeAgentDirectory());   // SEC-1 slice 4: the reserved agent has a phone

afterEach(function () {
    TenantContext::forget();
});

beforeEach(function () {
    config()->set('telephony.agent.endpoint', 'PJSIP/1003');
    config()->set('telephony.agent.directory', []);
    fakeNumberDirectory();
    Queue::fake();
});

/**
 * A tenant with one agent the board will hand back on every reservation.
 *
 * @return array{0: Tenant, 1: int}
 */
function timedCallTenant(): array
{
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, 'agent');
    fakeAgentRouter($agent->id);

    return [$tenant, $agent->id];
}

/** Every note in this client, newest first — the moments in transit. */
function notesFor(Tenant $tenant): Collection
{
    return TenantContext::run(
        $tenant->id,
        fn () => CallHandoff::query()->orderBy('id')->get(),
    );
}

it('stamps the caller\'s arrival on the note when the agent\'s phone is rung (CT-2)', function () {
    [$tenant] = timedCallTenant();

    $telephony = fakeTelephony();
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('placeCall')->once()->andReturn('agent-leg');

    $flow = new CallToAgentFlow($telephony, new Switchboard($telephony));
    $flow->handle(stasisStart('caller-leg', [], null, (string) $tenant->id));

    $note = notesFor($tenant)->first();

    expect($note->arrived_at)->not->toBeNull()
        // The ring moment is the note's OWN created_at — it is written immediately
        // before the phone is rung, so the carrier needed no column for it (CT-2).
        ->and($note->created_at)->not->toBeNull()
        ->and($note->arrived_at->lessThanOrEqualTo($note->created_at))->toBeTrue()
        // Nothing has happened yet beyond the ring.
        ->and($note->answered_at)->toBeNull()
        ->and($note->ended_at)->toBeNull();
});

it('stamps the pickup when caller and agent are joined, and the hang-up at teardown (CT-2/CT-6)', function () {
    [$tenant] = timedCallTenant();
    $session = new RecordingSession('caller-leg', 'call-1', 'snoop-said', 'snoop-heard');

    $telephony = fakeTelephony();
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('placeCall')->once()->andReturn('agent-leg');
    $telephony->shouldReceive('join')->once()->andReturn('conv-1');
    $telephony->shouldReceive('startRecording')->once()->andReturn($session);
    $telephony->shouldReceive('stopRecording')->once();
    $telephony->shouldReceive('hangup')->once()->with('agent-leg');
    $telephony->shouldReceive('endConversation')->once();

    $flow = new CallToAgentFlow($telephony, new Switchboard($telephony));
    $flow->handle(stasisStart('caller-leg', [], null, (string) $tenant->id));
    $flow->handle(stasisStart('agent-leg', ['agent']));   // agent picks up
    $flow->handle(channelDestroyed('caller-leg'));        // caller hangs up

    $note = notesFor($tenant)->first();

    expect($note->answered_at)->not->toBeNull()
        ->and($note->ended_at)->not->toBeNull()
        // The four moments in order — the whole point of one clock (CT-3).
        ->and($note->arrived_at->lessThanOrEqualTo($note->created_at))->toBeTrue()
        ->and($note->created_at->lessThanOrEqualTo($note->answered_at))->toBeTrue()
        ->and($note->answered_at->lessThanOrEqualTo($note->ended_at))->toBeTrue();
});

it('never stamps a moment onto another call\'s lingering note (CT-11)', function () {
    [$tenant, $agentId] = timedCallTenant();

    $telephony = fakeTelephony();
    $telephony->shouldReceive('answer')->twice();
    $telephony->shouldReceive('placeCall')->twice()->andReturn('agent-leg-1', 'agent-leg-2');

    $switchboard = new Switchboard($telephony);

    // Call one rings this agent and is then abandoned mid-ring, leaving its note behind.
    $first = new CallToAgentFlow($telephony, $switchboard);
    $first->handle(stasisStart('caller-1', [], null, (string) $tenant->id));
    $firstTicket = $first->ticketNumber();

    // Call two rings the same agent. Prune-on-write (TH-6) replaces the note, so the
    // stamp can only ever reach the CURRENT call's note — and it is matched on the
    // ticket besides, which is what protects the paths prune does not reach.
    $second = new CallToAgentFlow($telephony, $switchboard);
    $second->handle(stasisStart('caller-2', [], null, (string) $tenant->id));

    $notes = notesFor($tenant);

    expect($notes)->toHaveCount(1)
        ->and($notes->first()->ticket)->toBe($second->ticketNumber())
        ->and($notes->first()->ticket)->not->toBe($firstTicket)
        ->and($notes->first()->agent_user_id)->toBe($agentId);
});

/**
 * A live inbound call answered by Priya, with the phone system mocked through to the
 * point a second agent can be rung. Returns the flow and the router, whose agentId is
 * public and mutable so the transfer can reserve Rahul instead.
 *
 * @return array{0: CallToAgentFlow, 1: AgentRouter, 2: MockInterface}
 */
function callAnsweredBy(Tenant $tenant, int $priyaId): array
{
    $router = fakeAgentRouter($priyaId);
    $session = new RecordingSession('caller-leg', 'call-1', 'snoop-said', 'snoop-heard');

    $telephony = fakeTelephony();
    $telephony->shouldReceive('answer')->once();
    $telephony->shouldReceive('placeCall')->with('PJSIP/1003', 'agent', null, 20)->andReturn('agent-leg');
    $telephony->shouldReceive('join')->once()->andReturn('conv-1');
    $telephony->shouldReceive('startRecording')->once()->andReturn($session);
    $telephony->shouldReceive('placeCall')->with('PJSIP/1003', 'agent')->andReturn('transfer-leg');
    $telephony->shouldReceive('addToBridge')->zeroOrMoreTimes();
    $telephony->shouldReceive('removeFromBridge')->zeroOrMoreTimes();
    $telephony->shouldReceive('hangup')->zeroOrMoreTimes();
    $telephony->shouldReceive('stopRecording')->zeroOrMoreTimes();
    $telephony->shouldReceive('endConversation')->zeroOrMoreTimes();

    $flow = new CallToAgentFlow($telephony, new Switchboard($telephony));
    $flow->handle(stasisStart('caller-leg', [], null, (string) $tenant->id));
    $flow->handle(stasisStart('agent-leg', ['agent']));   // Priya picks up

    return [$flow, $router, $telephony];
}

/** One agent's note on this call. */
function noteFor(Tenant $tenant, int $agentId): ?CallHandoff
{
    return TenantContext::run(
        $tenant->id,
        fn (): ?CallHandoff => CallHandoff::query()->where('agent_user_id', $agentId)->first(),
    );
}

it('gives the transferred-to agent a fresh note with no arrival — the wait is the first agent\'s (CT-5)', function () {
    $tenant = Tenant::factory()->create();
    $priya = clientUserWithRole($tenant, 'agent');
    $rahul = clientUserWithRole($tenant, 'agent');

    // 🔴 The defect this also fixes, live on this branch today: Rahul is holding a note
    // from his PREVIOUS call. Without a fresh one his screen reads that stale ticket.
    TenantContext::run($tenant->id, fn () => CallHandoff::factory()
        ->forAgent($rahul)
        ->ticket('a-ticket-from-rahuls-last-call')
        ->create(['arrived_at' => now()->subHour()]));

    [$flow, $router] = callAnsweredBy($tenant, $priya->id);
    $router->agentId = $rahul->id;
    $flow->beginTransfer($tenant->id);

    $note = noteFor($tenant, $rahul->id);

    expect($note->ticket)->toBe($flow->ticketNumber())            // this call's ticket, not the stale one
        ->and($note->ticket)->not->toBe('a-ticket-from-rahuls-last-call')
        // When this call reached Rahul the customer was mid-conversation, not waiting.
        ->and($note->arrived_at)->toBeNull()
        // Priya keeps the wait, and hers alone.
        ->and(noteFor($tenant, $priya->id)->arrived_at)->not->toBeNull();
});

it('ends the transferring agent when she is dropped, not when the caller hangs up (CT-12)', function () {
    $tenant = Tenant::factory()->create();
    $priya = clientUserWithRole($tenant, 'agent');
    $rahul = clientUserWithRole($tenant, 'agent');

    [$flow, $router] = callAnsweredBy($tenant, $priya->id);
    $router->agentId = $rahul->id;
    $flow->beginTransfer($tenant->id);
    $flow->handle(stasisStart('transfer-leg', ['agent']));   // Rahul picks up, Priya is dropped

    $priyaEnd = noteFor($tenant, $priya->id)->ended_at;

    // Rahul then talks for another five minutes before the caller hangs up.
    $this->travel(5)->minutes();
    $flow->handle(channelDestroyed('caller-leg'));

    $priyaNote = noteFor($tenant, $priya->id);
    $rahulNote = noteFor($tenant, $rahul->id);

    // 🔴 Without this, Priya's row would carry NO end at all — the teardown stamp never
    // reaches her — and no transferred call would ever have talk time.
    expect($priyaNote->ended_at->timestamp)->toBe($priyaEnd->timestamp)   // first close wins (CT-12a)
        // Rahul's pickup does not travel connectAgent(); completeTransfer() is the only
        // place it exists.
        ->and($rahulNote->answered_at)->not->toBeNull()
        ->and($rahulNote->ended_at)->not->toBeNull()
        // Five minutes apart: her part ended long before the call did.
        ->and((int) $priyaNote->ended_at->diffInMinutes($rahulNote->ended_at))->toBe(5);
});

it('ends an agent who leaves a conference early, and closes the rest at the hang-up (CT-12a)', function () {
    $tenant = Tenant::factory()->create();
    $priya = clientUserWithRole($tenant, 'agent');
    $rahul = clientUserWithRole($tenant, 'agent');

    [$flow, $router] = callAnsweredBy($tenant, $priya->id);
    $router->agentId = $rahul->id;
    $flow->beginConference($tenant->id);
    $flow->handle(stasisStart('transfer-leg', ['agent']));   // Rahul joins; Priya stays

    // Priya says her bit and hangs up. Rahul carries on for six more minutes.
    $flow->handle(channelDestroyed('agent-leg'));
    $priyaEnd = noteFor($tenant, $priya->id)->ended_at;

    $this->travel(6)->minutes();
    $flow->handle(channelDestroyed('caller-leg'));

    $priyaNote = noteFor($tenant, $priya->id);
    $rahulNote = noteFor($tenant, $rahul->id);

    // The case the three stamp points missed: nothing stamps a conference walk-out, so
    // Priya's row took the caller's hang-up six minutes later.
    expect($priyaNote->ended_at->timestamp)->toBe($priyaEnd->timestamp)
        ->and((int) $priyaNote->ended_at->diffInMinutes($rahulNote->ended_at))->toBe(6);
});

it('closes BOTH agents on a conference that runs to the caller\'s hang-up (CT-12)', function () {
    $tenant = Tenant::factory()->create();
    $priya = clientUserWithRole($tenant, 'agent');
    $rahul = clientUserWithRole($tenant, 'agent');

    [$flow, $router] = callAnsweredBy($tenant, $priya->id);
    $router->agentId = $rahul->id;
    $flow->beginConference($tenant->id);
    $flow->handle(stasisStart('transfer-leg', ['agent']));
    $flow->handle(channelDestroyed('caller-leg'));   // both still connected when it ends

    // The teardown reaches every note on this call, not just the sole agent's — which
    // is what ticket-matching buys (CT-11), since there is no "sole agent" here.
    expect(noteFor($tenant, $priya->id)->ended_at)->not->toBeNull()
        ->and(noteFor($tenant, $rahul->id)->ended_at)->not->toBeNull()
        ->and(noteFor($tenant, $priya->id)->answered_at)->not->toBeNull()
        ->and(noteFor($tenant, $rahul->id)->answered_at)->not->toBeNull();
});

/**
 * An outbound call, driven the way the console drives it: the agent's own leg arrives
 * first carrying the customer number, the web's tracking id, the dialling agent and
 * their client (CS-4's fifth value), then we ring the customer.
 */
function outboundCallBy(Tenant $tenant, int $agentId, string $ticket): CallToAgentFlow
{
    $telephony = fakeTelephony();
    $telephony->shouldReceive('placeCall')->once()->andReturn('customer-leg');
    $telephony->shouldReceive('join')->zeroOrMoreTimes()->andReturn('conv-1');
    $telephony->shouldReceive('startRecording')->zeroOrMoreTimes()
        ->andReturn(new RecordingSession('customer-leg', 'call-1', 'snoop-said', 'snoop-heard'));
    $telephony->shouldReceive('stopRecording')->zeroOrMoreTimes();
    $telephony->shouldReceive('hangup')->zeroOrMoreTimes();
    $telephony->shouldReceive('endConversation')->zeroOrMoreTimes();

    $flow = new CallToAgentFlow($telephony, new Switchboard($telephony));
    $flow->handle(stasisStart('agent-leg', [
        'agent', '9991234567', $ticket, (string) $agentId, (string) $tenant->id,
    ]));

    return $flow;
}

it('carries a ring, a pickup and a hang-up on an outbound call — but never a wait (CT-16/CT-8)', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, 'agent');
    $ticket = (string) Str::uuid();

    $flow = outboundCallBy($tenant, $agent->id, $ticket);

    // The note exists the moment we start ringing the customer, and its own created_at
    // IS that moment — the outbound mirror of the inbound ring.
    $ringing = noteFor($tenant, $agent->id);
    expect($ringing->ticket)->toBe($ticket)
        ->and($ringing->arrived_at)->toBeNull();

    $flow->handle(stasisStart('customer-leg', ['outbound']));   // the customer answers
    $this->travel(3)->minutes();
    $flow->handle(channelDestroyed('customer-leg'));            // and hangs up

    $note = noteFor($tenant, $agent->id);

    expect($note->answered_at)->not->toBeNull()
        ->and($note->ended_at)->not->toBeNull()
        // Three minutes of conversation on a call that had no queue at all.
        ->and((int) $note->answered_at->diffInMinutes($note->ended_at))->toBe(3)
        // 🔴 CT-8, unchanged and still by construction: nobody waited, we placed the
        // call. Nothing in the code asks which direction this is.
        ->and($note->arrived_at)->toBeNull();
});

it('writes no note for global staff dialling with no client in scope (CT-16)', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, 'agent');

    $telephony = fakeTelephony();
    $telephony->shouldReceive('placeCall')->once()->andReturn('customer-leg');

    $flow = new CallToAgentFlow($telephony, new Switchboard($telephony));
    // Four args, not five: our own global staff dial from no client (CS-4's null case).
    $flow->handle(stasisStart('agent-leg', [
        'agent', '9991234567', (string) Str::uuid(), (string) $agent->id,
    ]));

    // Nothing to scope the write to, so that call stays out of the timing figures
    // rather than being filed under a made-up client.
    expect(TenantContext::cross(fn () => CallHandoff::query()->count()))->toBe(0);
});
