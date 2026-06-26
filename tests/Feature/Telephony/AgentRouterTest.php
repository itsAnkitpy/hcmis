<?php

use App\Enums\PresenceStatus;
use App\Enums\RoleName;
use App\Models\AgentPresence;
use App\Models\Tenant;
use App\Models\User;
use App\Telephony\AgentRouter;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(fn () => TenantContext::forget());

/**
 * B2.2b RD-3/RD-4 — the watcher's board-reader + reservation. Pick the first free
 * agent on a company's who's-free board and tag them "On a call" the instant we ring
 * them (race-proof via the proven B2.0 atomic grab), then release the tag if they
 * never connect (Fold B). All reads + writes are walled to the call's company (RLS,
 * the AttachRecordingToCall self-find). These prove the logic against the real DB;
 * the 2-phone live routing is verified at CP-B2.2b.
 */
if (! function_exists('seedPresenceRow')) {
    function seedPresenceRow(Tenant $tenant, PresenceStatus $status, bool $stale = false): User
    {
        $agent = clientUserWithRole($tenant, RoleName::Agent->value);

        TenantContext::run($tenant->id, function () use ($agent, $status, $stale): void {
            $factory = AgentPresence::factory()->forUser($agent)->status($status);

            if ($stale) {
                $factory = $factory->stale();
            }

            $factory->create();
        });

        return $agent;
    }
}

function statusOf(Tenant $tenant, User $agent): PresenceStatus
{
    return TenantContext::run(
        $tenant->id,
        fn (): PresenceStatus => AgentPresence::query()->where('user_id', $agent->id)->first()->status,
    );
}

it('reserves the first free agent (lowest user id) and tags them On a call', function () {
    $tenant = Tenant::factory()->create();
    $a = seedPresenceRow($tenant, PresenceStatus::Ready);
    $b = seedPresenceRow($tenant, PresenceStatus::Ready);

    $reserved = (new AgentRouter)->reserveFreeAgent($tenant->id);

    expect($reserved)->toBe($a->id)                          // first free = lowest user id (RD-3)
        ->and(statusOf($tenant, $a))->toBe(PresenceStatus::OnCall)   // tagged at ring (RD-4)
        ->and(statusOf($tenant, $b))->toBe(PresenceStatus::Ready);   // the other free agent untouched
});

it('skips agents who are not Ready (on break / wrapping up / on a call)', function () {
    $tenant = Tenant::factory()->create();
    seedPresenceRow($tenant, PresenceStatus::OnBreak);
    seedPresenceRow($tenant, PresenceStatus::WrappingUp);
    seedPresenceRow($tenant, PresenceStatus::OnCall);
    $free = seedPresenceRow($tenant, PresenceStatus::Ready);

    expect((new AgentRouter)->reserveFreeAgent($tenant->id))->toBe($free->id);
});

it('skips a Ready agent whose heartbeat has gone stale (reads as Offline, PD-4)', function () {
    $tenant = Tenant::factory()->create();
    seedPresenceRow($tenant, PresenceStatus::Ready, stale: true);   // looks Ready but heartbeat lapsed

    expect((new AgentRouter)->reserveFreeAgent($tenant->id))->toBeNull();
});

it('returns null when nobody is free (RD-5 all busy)', function () {
    $tenant = Tenant::factory()->create();
    seedPresenceRow($tenant, PresenceStatus::OnCall);
    seedPresenceRow($tenant, PresenceStatus::OnBreak);

    expect((new AgentRouter)->reserveFreeAgent($tenant->id))->toBeNull();
});

it('skips an already-reserved agent so a second call rings the next free one (no double-ring)', function () {
    $tenant = Tenant::factory()->create();
    $a = seedPresenceRow($tenant, PresenceStatus::Ready);
    $b = seedPresenceRow($tenant, PresenceStatus::Ready);

    $router = new AgentRouter;
    $first = $router->reserveFreeAgent($tenant->id);    // reserves A
    $second = $router->reserveFreeAgent($tenant->id);   // A is tagged → must reserve B

    expect($first)->toBe($a->id)
        ->and($second)->toBe($b->id)
        ->and($first)->not->toBe($second);
});

it('reserves atomically — a second reserve on the only free agent wins zero rows and returns null', function () {
    // The race guarantee (the proven B2.0 grab, reapplied): once A is tagged, the
    // conditional UPDATE "set on_call where still Ready" matches 0 rows for the loser,
    // exactly as two simultaneous callers would race in the lab.
    $tenant = Tenant::factory()->create();
    $a = seedPresenceRow($tenant, PresenceStatus::Ready);

    $router = new AgentRouter;

    expect($router->reserveFreeAgent($tenant->id))->toBe($a->id)    // winner
        ->and($router->reserveFreeAgent($tenant->id))->toBeNull();  // loser — A already taken
});

it('releases a reservation it set — flips On a call back to Ready (Fold B)', function () {
    $tenant = Tenant::factory()->create();
    $a = seedPresenceRow($tenant, PresenceStatus::Ready);

    $router = new AgentRouter;
    $router->reserveFreeAgent($tenant->id);               // A → on_call
    $router->releaseReservation($tenant->id, $a->id);     // no-answer → back to Ready

    expect(statusOf($tenant, $a))->toBe(PresenceStatus::Ready);
});

it('release is conditional — it never overwrites a status the screen set (not On a call)', function () {
    // The agent went On break during the ring window; a stray release must NOT yank
    // them back to Ready (Fold B: only undo the on_call WE set).
    $tenant = Tenant::factory()->create();
    $a = seedPresenceRow($tenant, PresenceStatus::OnBreak);

    (new AgentRouter)->releaseReservation($tenant->id, $a->id);

    expect(statusOf($tenant, $a))->toBe(PresenceStatus::OnBreak);   // untouched
});

it('walls the board per company — never reserves another company\'s free agent (RLS self-find)', function () {
    $mine = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    seedPresenceRow($other, PresenceStatus::Ready);   // a free agent, but in a DIFFERENT company

    expect((new AgentRouter)->reserveFreeAgent($mine->id))->toBeNull();   // my board is empty
});
