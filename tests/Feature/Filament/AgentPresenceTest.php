<?php

use App\Enums\PresenceStatus;
use App\Enums\RoleName;
use App\Filament\Pages\AgentConsole;
use App\Models\AgentPresence;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::forget();
});

/**
 * B2.2a — the who's-free board (presence). The agent's screen is the single writer
 * (PD-3): setPresence() upserts the one overwritten row per agent (PD-6), heartbeat()
 * keeps it alive (PD-4), and a stale row reads as Offline. The page methods are driven
 * directly (the path the browser runs over $wire), inside the agent's tenant context.
 * The auto-decline guard + the Break button are browser/JsSIP code — verified live at
 * CP-B2.2a the way B4 verified the screen, not here.
 */
it('upserts one board row per agent — a status change overwrites, never appends', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $this->actingAs($agent);

    TenantContext::run($tenant->id, function (): void {
        $page = new AgentConsole;
        $page->setPresence(PresenceStatus::Ready->value);
        $page->setPresence(PresenceStatus::OnCall->value);
        $page->setPresence(PresenceStatus::WrappingUp->value);
    });

    $rows = TenantContext::run($tenant->id, fn () => AgentPresence::query()->where('user_id', $agent->id)->get());

    expect($rows)->toHaveCount(1)                              // overwritten, not appended (PD-6)
        ->and($rows->first()->status)->toBe(PresenceStatus::WrappingUp); // the latest write wins
});

it('writes the right enum value for each screen transition', function (PresenceStatus $status) {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $this->actingAs($agent);

    TenantContext::run($tenant->id, fn () => (new AgentConsole)->setPresence($status->value));

    $row = TenantContext::run($tenant->id, fn () => AgentPresence::query()->where('user_id', $agent->id)->first());

    expect($row->status)->toBe($status);
})->with([
    'ready' => PresenceStatus::Ready,
    'on a call' => PresenceStatus::OnCall,
    'wrapping up' => PresenceStatus::WrappingUp,
    'on break' => PresenceStatus::OnBreak,
    'offline' => PresenceStatus::Offline,
]);

it('rejects an unknown presence status (the server is the wall)', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $this->actingAs($agent);

    TenantContext::run($tenant->id, fn () => (new AgentConsole)->setPresence('napping'));
})->throws(HttpException::class);

it('stamps last_seen_at when it sets presence', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $this->actingAs($agent);

    TenantContext::run($tenant->id, fn () => (new AgentConsole)->setPresence(PresenceStatus::Ready->value));

    $row = TenantContext::run($tenant->id, fn () => AgentPresence::query()->where('user_id', $agent->id)->first());

    expect($row->last_seen_at)->not->toBeNull()
        ->and($row->last_seen_at->diffInSeconds(now()))->toBeLessThan(5);
});

it('heartbeat refreshes last_seen_at without changing status', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    // An On-a-call row that last checked in two minutes ago.
    TenantContext::run($tenant->id, function () use ($agent): void {
        AgentPresence::factory()->forUser($agent)->status(PresenceStatus::OnCall)->create([
            'last_seen_at' => now()->subMinutes(2),
        ]);
    });

    $this->actingAs($agent);

    TenantContext::run($tenant->id, fn () => (new AgentConsole)->heartbeat());

    $row = TenantContext::run($tenant->id, fn () => AgentPresence::query()->where('user_id', $agent->id)->first());

    expect($row->status)->toBe(PresenceStatus::OnCall)                 // untouched (PD-4: heartbeat ≠ status)
        ->and($row->last_seen_at->diffInSeconds(now()))->toBeLessThan(5); // freshened
});

it('heartbeat with no board row yet is a harmless no-op', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $this->actingAs($agent);

    TenantContext::run($tenant->id, fn () => (new AgentConsole)->heartbeat());

    $count = TenantContext::run($tenant->id, fn () => AgentPresence::query()->where('user_id', $agent->id)->count());

    expect($count)->toBe(0); // heartbeat keeps an existing agent alive; it never resurrects a logged-out one
});

it('walls presence per client — one tenant never writes or sees another\'s board', function () {
    $clientA = Tenant::factory()->create();
    $clientB = Tenant::factory()->create();
    $agentA = clientUserWithRole($clientA, RoleName::Agent->value);

    // Client B already has a board row.
    TenantContext::run($clientB->id, fn () => AgentPresence::factory()->create());

    // Agent A sets their own presence from inside client A's context.
    $this->actingAs($agentA);
    TenantContext::run($clientA->id, fn () => (new AgentConsole)->setPresence(PresenceStatus::Ready->value));

    // Client A sees only its own row; client B's board is invisible across the wall.
    $aRows = TenantContext::run($clientA->id, fn () => AgentPresence::query()->get());
    expect($aRows)->toHaveCount(1)
        ->and($aRows->first()->user_id)->toBe($agentA->id);

    // Client B still has exactly its own one row — A's write never crossed over.
    $bRows = TenantContext::run($clientB->id, fn () => AgentPresence::query()->get());
    expect($bRows)->toHaveCount(1)
        ->and($bRows->first()->user_id)->not->toBe($agentA->id);
});

it('reads a stale Ready row as Offline, a fresh one as itself', function () {
    $tenant = Tenant::factory()->create();

    [$fresh, $stale, $offline] = TenantContext::run($tenant->id, fn (): array => [
        AgentPresence::factory()->status(PresenceStatus::Ready)->create(),          // fresh + ready
        AgentPresence::factory()->status(PresenceStatus::Ready)->stale()->create(), // ready but gone quiet
        AgentPresence::factory()->status(PresenceStatus::Offline)->create(),        // explicitly offline
    ]);

    expect($fresh->effectiveStatus())->toBe(PresenceStatus::Ready)
        ->and($stale->effectiveStatus())->toBe(PresenceStatus::Offline)   // a crashed tab can't lie "Ready" (PD-4)
        ->and($offline->effectiveStatus())->toBe(PresenceStatus::Offline)
        ->and($stale->isStale())->toBeTrue()
        ->and($fresh->isStale())->toBeFalse();
});
