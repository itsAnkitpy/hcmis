<?php

use App\Enums\PresenceStatus;
use App\Enums\RoleName;
use App\Models\AgentPresence;
use App\Models\Department;
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
    /**
     * An agent on this company's board. They hold a phone unless the test says
     * otherwise: SEC-1 slice 4 made an extension the price of being reservable, and
     * in the app every agent gets one the moment they are made one.
     */
    function seedPresenceRow(Tenant $tenant, PresenceStatus $status, bool $stale = false, bool $withPhone = true): User
    {
        $agent = clientUserWithRole($tenant, RoleName::Agent->value);

        if ($withPhone) {
            // forceFill, not fill: sip_extension is deliberately not fillable (PP-1).
            $agent->forceFill(['sip_extension' => (string) (1100 + $agent->id)])->save();
        }

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

/*
| B2.3b-i QD-4 — the per-call skip list. A waiting caller must not be handed back an
| agent whose phone they have already heard ring out; without this they cycle between
| hold music and one silent desk indefinitely. The skip is per CALL: the agent stays
| Ready and free for everybody else, which is the deliberate difference from taking
| them off the board entirely.
*/

it('skips an agent this caller has already been rung out on (QD-4)', function () {
    $tenant = Tenant::factory()->create();
    $first = seedPresenceRow($tenant, PresenceStatus::Ready);
    $second = seedPresenceRow($tenant, PresenceStatus::Ready);

    $reserved = (new AgentRouter)->reserveFreeAgent($tenant->id, [$first->id]);

    expect($reserved)->toBe($second->id)
        ->and(statusOf($tenant, $first))->toBe(PresenceStatus::Ready);   // untouched, still free for others
});

it('reports nobody free once this caller has been rung out on everyone (QD-4)', function () {
    $tenant = Tenant::factory()->create();
    $only = seedPresenceRow($tenant, PresenceStatus::Ready);

    expect((new AgentRouter)->reserveFreeAgent($tenant->id, [$only->id]))->toBeNull()
        ->and(statusOf($tenant, $only))->toBe(PresenceStatus::Ready);
});

/**
 * 🔴 SEC-1 slice 4 — an agent with no phone is not a free agent.
 *
 * The phone directory stopped falling back to one shared extension, so ringing
 * someone who holds none would hand a null to `placeCall()` and kill the listener
 * mid-call. The guard sits here, in the one place that decides who can be rung,
 * rather than at each of the three ring sites — so a phoneless agent is simply never
 * a candidate and the caller waits exactly as they do when nobody is free.
 */
it('never reserves a Ready agent who holds no phone (SEC-1 PP-12)', function () {
    $tenant = Tenant::factory()->create();
    $phoneless = seedPresenceRow($tenant, PresenceStatus::Ready, withPhone: false);

    expect((new AgentRouter)->reserveFreeAgent($tenant->id))->toBeNull()
        ->and(statusOf($tenant, $phoneless))->toBe(PresenceStatus::Ready);   // never tagged, so never rung
});

it('rings past a phoneless agent to the next free one who does hold a phone', function () {
    $tenant = Tenant::factory()->create();
    $phoneless = seedPresenceRow($tenant, PresenceStatus::Ready, withPhone: false);
    $withPhone = seedPresenceRow($tenant, PresenceStatus::Ready);

    expect((new AgentRouter)->reserveFreeAgent($tenant->id))->toBe($withPhone->id)
        ->and(statusOf($tenant, $phoneless))->toBe(PresenceStatus::Ready);
});

/*
| inbound-audio slice 7 — departments. The department's free agents first; anyone
| else only when the flow says the caller may widen (D3). No department is today's
| query (D8), which every test above already pins.
*/

function departmentOf(Tenant $tenant, User ...$members): Department
{
    return TenantContext::run($tenant->id, function () use ($members): Department {
        $department = Department::factory()->create();
        $department->members()->attach(array_map(fn (User $member): int => $member->id, $members));

        return $department;
    });
}

it('gives the department first pick even when a lower-numbered outsider is free', function (bool $mayWiden) {
    $tenant = Tenant::factory()->create();
    $outsider = seedPresenceRow($tenant, PresenceStatus::Ready);
    $member = seedPresenceRow($tenant, PresenceStatus::Ready);
    $department = departmentOf($tenant, $member);

    // Widening never takes first pick away from the department (D3).
    expect((new AgentRouter)->reserveFreeAgent($tenant->id, [], $department->id, $mayWiden))->toBe($member->id)
        ->and(statusOf($tenant, $outsider))->toBe(PresenceStatus::Ready);
})->with(['may not widen' => false, 'may widen' => true]);

it('waits rather than taking an outsider when the department is busy and may not widen', function () {
    $tenant = Tenant::factory()->create();
    $outsider = seedPresenceRow($tenant, PresenceStatus::Ready);
    $department = departmentOf($tenant, seedPresenceRow($tenant, PresenceStatus::OnCall));

    expect((new AgentRouter)->reserveFreeAgent($tenant->id, [], $department->id, false))->toBeNull()
        ->and(statusOf($tenant, $outsider))->toBe(PresenceStatus::Ready);
});

it('takes an outsider when the department is busy and the caller may widen', function () {
    $tenant = Tenant::factory()->create();
    $outsider = seedPresenceRow($tenant, PresenceStatus::Ready);
    $department = departmentOf($tenant, seedPresenceRow($tenant, PresenceStatus::OnCall));

    expect((new AgentRouter)->reserveFreeAgent($tenant->id, [], $department->id, true))->toBe($outsider->id);
});

it('applies the per-call skip list inside the department (QD-4)', function () {
    $tenant = Tenant::factory()->create();
    $first = seedPresenceRow($tenant, PresenceStatus::Ready);
    $second = seedPresenceRow($tenant, PresenceStatus::Ready);
    $department = departmentOf($tenant, $first, $second);

    expect((new AgentRouter)->reserveFreeAgent($tenant->id, [$first->id], $department->id))->toBe($second->id)
        ->and((new AgentRouter)->reserveFreeAgent($tenant->id, [$first->id], $department->id))->toBeNull();
});

it('counts a member as logged in on any status but offline, on break included (D4)', function (PresenceStatus $status, bool $loggedIn) {
    $tenant = Tenant::factory()->create();
    $department = departmentOf($tenant, seedPresenceRow($tenant, $status));

    expect((new AgentRouter)->isAnyoneInDepartmentLoggedIn($tenant->id, $department->id))->toBe($loggedIn);
})->with([
    'ready' => [PresenceStatus::Ready, true],
    'on a call' => [PresenceStatus::OnCall, true],
    'wrapping up' => [PresenceStatus::WrappingUp, true],
    'on break' => [PresenceStatus::OnBreak, true],
    'offline' => [PresenceStatus::Offline, false],
]);

it('does not count a member whose heartbeat went stale, or who holds no phone', function (bool $stale, bool $withPhone) {
    $tenant = Tenant::factory()->create();
    $department = departmentOf($tenant, seedPresenceRow($tenant, PresenceStatus::Ready, $stale, $withPhone));

    expect((new AgentRouter)->isAnyoneInDepartmentLoggedIn($tenant->id, $department->id))->toBeFalse();
})->with(['stale heartbeat' => [true, true], 'no phone' => [false, false]]);

it('treats an empty department as nobody logged in, while an outsider is at work', function () {
    $tenant = Tenant::factory()->create();
    seedPresenceRow($tenant, PresenceStatus::Ready);
    $department = departmentOf($tenant);

    expect((new AgentRouter)->isAnyoneInDepartmentLoggedIn($tenant->id, $department->id))->toBeFalse();
});
