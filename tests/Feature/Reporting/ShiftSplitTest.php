<?php

use App\Enums\PresenceStatus;
use App\Enums\StintEndedVia;
use App\Models\AgentPresence;
use App\Models\AgentStatusHistory;
use App\Models\Tenant;
use App\Models\User;
use App\Reporting\ShiftSplit;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::resetWebRequest();
    TenantContext::forget();
});

/**
 * A closed stint at a known time — the past-day case, where every stint has a real end
 * and the dead-session rule never has to guess.
 */
function shiftStint(User $user, PresenceStatus $status, Carbon $from, Carbon $to): AgentStatusHistory
{
    return AgentStatusHistory::factory()->forUser($user)->create([
        'status' => $status,
        'started_at' => $from,
        'ended_at' => $to,
        'ended_via' => StintEndedVia::Changed,
    ]);
}

// --- apr.md AP-4: the four statuses always add up to the logged-in total ---

it('sums seconds per status and adds them up to the logged-in total', function () {
    $tenant = Tenant::factory()->create();

    [$split, $agentId] = TenantContext::run($tenant->id, function (): array {
        $agent = User::factory()->create();
        $day = Carbon::today()->subDays(3);

        shiftStint($agent, PresenceStatus::Ready, $day->copy()->setTime(9, 0), $day->copy()->setTime(10, 0));       // 3600
        shiftStint($agent, PresenceStatus::OnCall, $day->copy()->setTime(10, 0), $day->copy()->setTime(10, 30));    // 1800
        shiftStint($agent, PresenceStatus::OnBreak, $day->copy()->setTime(10, 30), $day->copy()->setTime(11, 0));   // 1800
        shiftStint($agent, PresenceStatus::WrappingUp, $day->copy()->setTime(11, 0), $day->copy()->setTime(11, 15)); // 900

        return [
            (new ShiftSplit)->forAgents([$agent->id], $day->copy()->startOfDay(), $day->copy()->endOfDay()),
            $agent->id,
        ];
    });

    $row = $split[$agentId];

    expect($row['seconds'])->toBe(['ready' => 3600, 'on_call' => 1800, 'on_break' => 1800, 'wrapping_up' => 900])
        // Offline opens no stint, so the four are the whole logged-in day.
        ->and($row['active'])->toBe(8100)
        ->and(array_sum($row['seconds']))->toBe($row['active'])
        ->and($row['firstLogin']->format('H:i'))->toBe('09:00')
        ->and($row['lastActivity']->format('H:i'))->toBe('11:15');
});

// --- AP-4: the range, not the day. A stint counts only the part inside it ---

it('counts only the part of a stint that falls inside the range', function () {
    $tenant = Tenant::factory()->create();

    [$split, $agentId] = TenantContext::run($tenant->id, function (): array {
        $agent = User::factory()->create();
        $day = Carbon::today()->subDays(3);

        // 20:00 the day before → 06:00 on the chosen day: only 00:00→06:00 is inside.
        shiftStint(
            $agent,
            PresenceStatus::Ready,
            $day->copy()->subDay()->setTime(20, 0),
            $day->copy()->setTime(6, 0),
        );

        return [
            (new ShiftSplit)->forAgents([$agent->id], $day->copy()->startOfDay(), $day->copy()->endOfDay()),
            $agent->id,
        ];
    });

    expect($split[$agentId]['seconds']['ready'])->toBe(21600) // 6h, not 10h
        ->and($split[$agentId]['firstLogin']->format('H:i'))->toBe('00:00');
});

it('counts the whole range for a stint that spans all of it', function () {
    $tenant = Tenant::factory()->create();

    [$split, $agentId] = TenantContext::run($tenant->id, function (): array {
        $agent = User::factory()->create();
        $day = Carbon::today()->subDays(3);

        shiftStint(
            $agent,
            PresenceStatus::Ready,
            $day->copy()->subDay()->setTime(8, 0),
            $day->copy()->addDay()->setTime(8, 0),
        );

        return [
            (new ShiftSplit)->forAgents([$agent->id], $day->copy()->startOfDay(), $day->copy()->endOfDay()),
            $agent->id,
        ];
    });

    // A whole day, to the second Carbon's endOfDay stops at.
    expect($split[$agentId]['seconds']['ready'])->toBe(86399);
});

// --- BK-6: an open stint whose session died closes on the stale rule ---

it('closes a dead session on the stale rule instead of counting up to now', function () {
    $tenant = Tenant::factory()->create();

    [$split, $agentId] = TenantContext::run($tenant->id, function (): array {
        $agent = User::factory()->create();

        // Heartbeat went quiet five minutes ago; the stint is still open on disk.
        AgentPresence::factory()->forUser($agent)->stale()->create();
        AgentStatusHistory::factory()->forUser($agent)->status(PresenceStatus::Ready)
            ->create(['started_at' => now()->subHour()]);

        return [
            (new ShiftSplit)->forAgents([$agent->id], now()->startOfDay(), now()->endOfDay()),
            $agent->id,
        ];
    });

    $staleAfter = (int) config('telephony.presence.stale_after_seconds');

    // An hour minus the five silent minutes, plus the stale window the write side will
    // eventually stamp — never the full hour a naive "count up to now" would give.
    expect($split[$agentId]['seconds']['ready'])->toBe(3600 - 300 + $staleAfter)
        ->and($split[$agentId]['stints'][0]['endedAt'])->not->toBeNull();
});

it('leaves a live stint open and counts it up to now', function () {
    $tenant = Tenant::factory()->create();

    [$split, $agentId] = TenantContext::run($tenant->id, function (): array {
        $agent = User::factory()->create();

        AgentPresence::factory()->forUser($agent)->status(PresenceStatus::Ready)->create(); // fresh
        AgentStatusHistory::factory()->forUser($agent)->status(PresenceStatus::Ready)
            ->create(['started_at' => now()->subHour()]);

        return [
            (new ShiftSplit)->forAgents([$agent->id], now()->startOfDay(), now()->endOfDay()),
            $agent->id,
        ];
    });

    expect($split[$agentId]['seconds']['ready'])->toBeGreaterThanOrEqual(3590)
        ->and($split[$agentId]['seconds']['ready'])->toBeLessThanOrEqual(3610)
        ->and($split[$agentId]['stints'][0]['endedAt'])->toBeNull();
});

// --- AP-2: an agent who never logged in still gets a row, and it reads zero ---

it('gives every requested agent a row, even one with no stints at all', function () {
    $tenant = Tenant::factory()->create();

    [$split, $ids] = TenantContext::run($tenant->id, function (): array {
        $worked = User::factory()->create();
        $absent = User::factory()->create();
        $day = Carbon::today()->subDays(2);

        shiftStint($worked, PresenceStatus::Ready, $day->copy()->setTime(9, 0), $day->copy()->setTime(10, 0));

        return [
            (new ShiftSplit)->forAgents([$worked->id, $absent->id], $day->copy()->startOfDay(), $day->copy()->endOfDay()),
            ['worked' => $worked->id, 'absent' => $absent->id],
        ];
    });

    expect($split)->toHaveCount(2)
        ->and($split[$ids['worked']]['active'])->toBe(3600)
        ->and($split[$ids['absent']]['active'])->toBe(0)
        ->and($split[$ids['absent']]['stints'])->toBe([])
        ->and($split[$ids['absent']]['firstLogin'])->toBeNull();
});

it('drops a null agent id — the Unassigned row is not a person and has no shift', function () {
    $tenant = Tenant::factory()->create();

    $split = TenantContext::run(
        $tenant->id,
        fn (): array => (new ShiftSplit)->forAgents([null], now()->startOfDay(), now()->endOfDay()),
    );

    expect($split)->toBe([]);
});

// --- AP-4: two queries, however many agents ---

it('reads every agent in a fixed number of queries', function () {
    $tenant = Tenant::factory()->create();

    $ids = TenantContext::run($tenant->id, function (): array {
        $day = Carbon::today()->subDays(2);
        $ids = [];

        foreach (range(1, 12) as $ignored) {
            $agent = User::factory()->create();
            AgentPresence::factory()->forUser($agent)->create();
            shiftStint($agent, PresenceStatus::Ready, $day->copy()->setTime(9, 0), $day->copy()->setTime(10, 0));
            shiftStint($agent, PresenceStatus::OnCall, $day->copy()->setTime(10, 0), $day->copy()->setTime(11, 0));
            $ids[] = $agent->id;
        }

        return $ids;
    });

    $day = Carbon::today()->subDays(2);

    DB::enableQueryLog();
    DB::flushQueryLog();

    $split = TenantContext::run(
        $tenant->id,
        fn (): array => (new ShiftSplit)->forAgents($ids, $day->copy()->startOfDay(), $day->copy()->endOfDay()),
    );

    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // The two reads, plus the tenant-context SETs around the run. A per-agent read
    // would put this past twenty-four.
    expect($queries)->toBeLessThan(10)
        ->and($split)->toHaveCount(12);
});

// --- RP-4: the tenant wall — client A never reads client B's stints ---

it('returns no stints for an agent of another client', function () {
    $clientA = Tenant::factory()->create();
    $clientB = Tenant::factory()->create();
    $day = Carbon::today()->subDays(2);

    $agentB = TenantContext::run($clientB->id, function () use ($day): User {
        $agent = User::factory()->create();
        shiftStint($agent, PresenceStatus::Ready, $day->copy()->setTime(9, 0), $day->copy()->setTime(17, 0));

        return $agent;
    });

    // Read as client A. The stints are client B's, so the wall hides them and the row
    // reads zero rather than leaking eight hours of another client's shift.
    $split = TenantContext::run(
        $clientA->id,
        fn (): array => (new ShiftSplit)->forAgents([$agentB->id], $day->copy()->startOfDay(), $day->copy()->endOfDay()),
    );

    expect($split[$agentB->id]['active'])->toBe(0);
});
