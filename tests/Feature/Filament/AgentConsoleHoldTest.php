<?php

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
 * Hold — the console's half (PRD/phase-2/hold.md H-3/H-7).
 *
 * The always-on program leaves the held total on the pigeonhole note; the wrap-up copies
 * it onto the call row at the Done click, exactly as it already does for the four timing
 * moments and for who hung up. Talked then reads the held part OUT.
 *
 * The program's half is in tests/Feature/Telephony/CallHoldTest.php.
 */
uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::forget();
});

beforeEach(function () {
    Http::fake(['*' => Http::response(['id' => 'agent-leg'])]);
});

/**
 * A console holding the ticket of a note carrying the given fields. Local to this file
 * rather than borrowed from a sibling, so running this file alone still works.
 *
 * @param  array<string, mixed>  $note
 */
function consoleWithHoldNote(Tenant $tenant, User $agent, array $note): AgentConsole
{
    $ticket = (string) Str::uuid();

    TenantContext::run($tenant->id, fn () => CallHandoff::factory()
        ->forAgent($agent)
        ->ticket($ticket)
        ->create($note));

    $page = new AgentConsole;
    $page->callCorrelationId = $ticket;
    $page->callPartyNumber = '9991234567';

    return $page;
}

/** The one row this client has. */
function holdRow(Tenant $tenant): ?Call
{
    return TenantContext::run($tenant->id, fn (): ?Call => Call::query()->latest('id')->first());
}

it('copies the held total from the note onto the call row', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $this->actingAs($agent);

    $page = consoleWithHoldNote($tenant, $agent, [
        'arrived_at' => now()->subMinutes(6),
        'answered_at' => now()->subMinutes(5),
        'ended_at' => now(),
        'hold_seconds' => 180,
    ]);

    TenantContext::run($tenant->id, fn () => $page->completeUnmatched());

    expect(holdRow($tenant)->hold_seconds)->toBe(180);
});

// 🔴 H-3, and the one number on a screen Ankit has already demoed that this changes.
// Today "Talked" is pickup to hang-up. A five-minute call with three minutes of music in
// it would otherwise read as five minutes of conversation.
it('reads the held part out of Talked', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $this->actingAs($agent);

    $page = consoleWithHoldNote($tenant, $agent, [
        'answered_at' => now()->subMinutes(5),
        'ended_at' => now(),
        'hold_seconds' => 180,
    ]);

    TenantContext::run($tenant->id, fn () => $page->completeUnmatched());

    // Five minutes on the line, three of them music: two minutes of conversation.
    expect(holdRow($tenant)->talkedSeconds())->toBe(120);
});

// A call that was never held reads exactly as it does today — nothing moves until
// somebody actually presses Hold.
it('leaves Talked alone on a call that was never held', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $this->actingAs($agent);

    $page = consoleWithHoldNote($tenant, $agent, [
        'answered_at' => now()->subMinutes(5),
        'ended_at' => now(),
    ]);

    TenantContext::run($tenant->id, fn () => $page->completeUnmatched());

    $call = holdRow($tenant);

    // H-7: the program handled this call and it was never held, so zero is a measurement
    // and it is true. Blank would claim we know nothing about it.
    expect($call->hold_seconds)->toBe(0)
        ->and($call->talkedSeconds())->toBe(300);
});

// The other side of that distinction. No note means the program left us nothing at all —
// the call it handled was before Hold shipped, or the note write failed (TH-2). Zero
// would be a claim we cannot support.
it('leaves the held figure blank when there is no note for this call', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $this->actingAs($agent);

    $page = new AgentConsole;
    $page->callPartyNumber = '9991234567';

    TenantContext::run($tenant->id, fn () => $page->completeUnmatched());

    expect(holdRow($tenant)->hold_seconds)->toBeNull();
});
