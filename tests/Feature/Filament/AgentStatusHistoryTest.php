<?php

use App\Enums\PresenceStatus;
use App\Enums\RoleName;
use App\Enums\StintEndedVia;
use App\Filament\Pages\AgentConsole;
use App\Models\AgentPresence;
use App\Models\AgentStatusHistory;
use App\Models\BreakCategory;
use App\Models\Tenant;
use App\Telephony\AgentRouter;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::forget();
});

/**
 * BK-2 — the status history: one row per stint, written from the single door
 * (AgentConsole::setPresence, the path the browser runs over $wire), closed by
 * the next change or lazily after a dead session (BK-6, no scheduler). The
 * page methods are driven directly inside the agent's tenant context, the
 * AgentPresenceTest pattern.
 */

/** Drive setPresence as the browser would, inside the agent's client. */
function setAgentPresence(Tenant $tenant, string $status, ?int $breakCategoryId = null): void
{
    TenantContext::run($tenant->id, fn () => (new AgentConsole)->setPresence($status, $breakCategoryId));
}

/** The agent's stints, oldest first, read inside their client. */
function stintsFor(Tenant $tenant, int $userId): Collection
{
    return TenantContext::run(
        $tenant->id,
        fn () => AgentStatusHistory::query()->where('user_id', $userId)->orderBy('started_at')->orderBy('id')->get(),
    );
}

it('closes the open stint and opens the next on a status change', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $this->actingAs($agent);
    setAgentPresence($tenant, PresenceStatus::Ready->value);
    setAgentPresence($tenant, PresenceStatus::OnCall->value);

    $stints = stintsFor($tenant, $agent->id);

    expect($stints)->toHaveCount(2)
        ->and($stints[0]->status)->toBe(PresenceStatus::Ready)
        ->and($stints[0]->ended_at)->not->toBeNull()                 // closed by the change
        ->and($stints[0]->ended_via)->toBe(StintEndedVia::Changed)
        ->and($stints[1]->status)->toBe(PresenceStatus::OnCall)
        ->and($stints[1]->ended_at)->toBeNull();                     // the new open stint
});

it('treats the same status twice as a no-op — no duplicate stints', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $this->actingAs($agent);
    setAgentPresence($tenant, PresenceStatus::Ready->value);
    setAgentPresence($tenant, PresenceStatus::Ready->value);

    $stints = stintsFor($tenant, $agent->id);

    expect($stints)->toHaveCount(1)
        ->and($stints[0]->ended_at)->toBeNull(); // still the same open stint
});

it('snapshots the break category and its limit as they stood (BK-2)', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $category = TenantContext::run(
        $tenant->id,
        fn () => BreakCategory::factory()->withLimit(30)->create(['code' => 'LUNCH_TEST']),
    );

    $this->actingAs($agent);
    setAgentPresence($tenant, PresenceStatus::OnBreak->value, $category->id);

    // The admin later changes the limit — yesterday's stint must keep telling
    // yesterday's truth.
    TenantContext::run($tenant->id, fn () => $category->fresh()->update(['time_limit_minutes' => 45]));

    $stint = stintsFor($tenant, $agent->id)->first();

    expect($stint->status)->toBe(PresenceStatus::OnBreak)
        ->and($stint->break_category_id)->toBe($category->id)
        ->and($stint->limit_minutes)->toBe(30); // the limit AT THE TIME, not today's
});

it('logs an untyped break with no category and no limit (the BK-3 fallback)', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $this->actingAs($agent);
    setAgentPresence($tenant, PresenceStatus::OnBreak->value);

    $stint = stintsFor($tenant, $agent->id)->first();

    expect($stint->status)->toBe(PresenceStatus::OnBreak)
        ->and($stint->break_category_id)->toBeNull()
        ->and($stint->limit_minutes)->toBeNull();
});

it('degrades a deactivated category to an untyped break — the server is the wall', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $inactive = TenantContext::run(
        $tenant->id,
        fn () => BreakCategory::factory()->inactive()->withLimit(15)->create(['code' => 'RETIRED_TEST']),
    );

    $this->actingAs($agent);
    setAgentPresence($tenant, PresenceStatus::OnBreak->value, $inactive->id);

    $stint = stintsFor($tenant, $agent->id)->first();

    expect($stint->break_category_id)->toBeNull()
        ->and($stint->limit_minutes)->toBeNull();
});

it('degrades another client\'s category id to an untyped break (the tenant wall)', function () {
    $clientA = Tenant::factory()->create();
    $clientB = Tenant::factory()->create();
    $agentA = clientUserWithRole($clientA, RoleName::Agent->value);

    $foreign = TenantContext::run(
        $clientB->id,
        fn () => BreakCategory::factory()->withLimit(60)->create(['code' => 'FOREIGN_TEST']),
    );

    $this->actingAs($agentA);
    setAgentPresence($clientA, PresenceStatus::OnBreak->value, $foreign->id);

    $stint = stintsFor($clientA, $agentA->id)->first();

    expect($stint->break_category_id)->toBeNull()
        ->and($stint->limit_minutes)->toBeNull();
});

it('closes the stint and opens nothing on Offline — offline is the gap between stints', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $this->actingAs($agent);
    setAgentPresence($tenant, PresenceStatus::Ready->value);
    setAgentPresence($tenant, PresenceStatus::Offline->value);

    $stints = stintsFor($tenant, $agent->id);

    expect($stints)->toHaveCount(1)
        ->and($stints[0]->status)->toBe(PresenceStatus::Ready)
        ->and($stints[0]->ended_at)->not->toBeNull()
        ->and($stints[0]->ended_via)->toBe(StintEndedVia::Changed);
});

it('lazily closes a dead session\'s stint at last-seen + the stale window, marked stale (BK-6)', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    // Second-aligned: the columns store whole seconds, and this test asserts exact arithmetic.
    $lastSeen = now()->subMinutes(10)->startOfSecond();

    // A session that died mid-Ready: quiet board row + a dangling open stint.
    TenantContext::run($tenant->id, function () use ($agent, $lastSeen): void {
        AgentPresence::factory()->forUser($agent)->status(PresenceStatus::Ready)->create(['last_seen_at' => $lastSeen]);
        AgentStatusHistory::factory()->forUser($agent)->status(PresenceStatus::Ready)->create(['started_at' => now()->subMinutes(30)]);
    });

    // Next morning's login writes Ready again — SAME status, but a new session:
    // staleness must beat the no-op (a new stay, never a continuation).
    $this->actingAs($agent);
    setAgentPresence($tenant, PresenceStatus::Ready->value);

    $stints = stintsFor($tenant, $agent->id);
    $window = (int) config('telephony.presence.stale_after_seconds');

    expect($stints)->toHaveCount(2)
        ->and($stints[0]->ended_via)->toBe(StintEndedVia::Stale)
        // Closed when the session effectively ended, not when we noticed.
        ->and($stints[0]->ended_at->equalTo($lastSeen->copy()->addSeconds($window)))->toBeTrue()
        ->and($stints[1]->ended_at)->toBeNull(); // the fresh stint opened anyway
});

it('never dates a lazy close before the stint started', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    // Second-aligned: the columns store whole seconds, and this test asserts exact arithmetic.
    $startedAt = now()->subMinutes(2)->startOfSecond();

    // Pathological clock: the heartbeat stamp predates the stint's own start.
    TenantContext::run($tenant->id, function () use ($agent, $startedAt): void {
        AgentPresence::factory()->forUser($agent)->status(PresenceStatus::Ready)->create(['last_seen_at' => now()->subMinutes(20)]);
        AgentStatusHistory::factory()->forUser($agent)->status(PresenceStatus::Ready)->create(['started_at' => $startedAt]);
    });

    $this->actingAs($agent);
    setAgentPresence($tenant, PresenceStatus::OnCall->value);

    $closed = stintsFor($tenant, $agent->id)->first();

    expect($closed->ended_via)->toBe(StintEndedVia::Stale)
        ->and($closed->ended_at->equalTo($startedAt))->toBeTrue(); // floored at the open
});

it('writes no history from a heartbeat — "still here" is not a status', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $this->actingAs($agent);
    setAgentPresence($tenant, PresenceStatus::Ready->value);
    TenantContext::run($tenant->id, fn () => (new AgentConsole)->heartbeat());

    $stints = stintsFor($tenant, $agent->id);

    expect($stints)->toHaveCount(1)
        ->and($stints[0]->ended_at)->toBeNull(); // untouched by the ping
});

it('writes no history from the router\'s ring-time reservation — a routing lock, not a stint', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    // Agent genuinely Ready through the door (board row + open Ready stint).
    $this->actingAs($agent);
    setAgentPresence($tenant, PresenceStatus::Ready->value);

    // A call rings them: the router tags the BOARD On-a-call (RD-4)…
    $reserved = (new AgentRouter)->reserveFreeAgent($tenant->id);

    $stints = stintsFor($tenant, $agent->id);
    $board = TenantContext::run($tenant->id, fn () => AgentPresence::query()->where('user_id', $agent->id)->first());

    expect($reserved)->toBe($agent->id)
        ->and($board->status)->toBe(PresenceStatus::OnCall)          // …the board carries the tag…
        ->and($stints)->toHaveCount(1)
        ->and($stints[0]->status)->toBe(PresenceStatus::Ready)
        ->and($stints[0]->ended_at)->toBeNull();                     // …but history is untouched
});

it('walls history per client — one tenant never sees another\'s stints', function () {
    $clientA = Tenant::factory()->create();
    $clientB = Tenant::factory()->create();
    $agentA = clientUserWithRole($clientA, RoleName::Agent->value);

    $this->actingAs($agentA);
    setAgentPresence($clientA, PresenceStatus::Ready->value);

    $seenFromB = TenantContext::run($clientB->id, fn () => AgentStatusHistory::query()->count());
    $seenFromA = TenantContext::run($clientA->id, fn () => AgentStatusHistory::query()->count());

    expect($seenFromB)->toBe(0)
        ->and($seenFromA)->toBe(1);
});

it('refuses to delete a category that history points at — the database backstop (BK-1)', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $category = TenantContext::run(
        $tenant->id,
        fn () => BreakCategory::factory()->withLimit(20)->create(['code' => 'PINNED_TEST']),
    );

    $this->actingAs($agent);
    setAgentPresence($tenant, PresenceStatus::OnBreak->value, $category->id);

    // Even a path above the policy (super_admin, tinker) hits the FK wall.
    TenantContext::run($tenant->id, fn () => $category->fresh()->delete());
})->throws(QueryException::class);
