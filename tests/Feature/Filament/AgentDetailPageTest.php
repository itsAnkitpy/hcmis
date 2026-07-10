<?php

use App\Enums\PresenceStatus;
use App\Enums\RoleName;
use App\Enums\StintEndedVia;
use App\Filament\Pages\AgentDetail;
use App\Filament\Pages\LiveAgents;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\AgentPresence;
use App\Models\AgentStatusHistory;
use App\Models\Tenant;
use App\Models\User;
use App\Reporting\CallReportFilters;
use App\Reporting\CallReportService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::resetWebRequest();
    TenantContext::forget();
});

/**
 * A closed stint on a specific day/time — the past-day case where every stint's end
 * is real (no dead-session handling needed).
 */
function stint(User $user, PresenceStatus $status, Carbon $from, Carbon $to): AgentStatusHistory
{
    return AgentStatusHistory::factory()->forUser($user)->create([
        'status' => $status,
        'started_at' => $from,
        'ended_at' => $to,
        'ended_via' => StintEndedVia::Changed,
    ]);
}

/**
 * Build a page bound to one agent + day, ready to call its compute methods — the
 * (new LiveAgents)->roster() shape, adapted for a page that takes a mount argument.
 */
function agentDetailFor(User $agent, ?string $date = null): AgentDetail
{
    $page = new AgentDetail;
    $page->agentId = $agent->id;
    $page->agentName = $agent->name;
    $page->date = $date;

    return $page;
}

// --- AD-2 §1: time-per-status sum over a chosen past day (all-closed stints) ---

it('sums time per status for a chosen past day', function () {
    $tenant = Tenant::factory()->create();

    $summary = TenantContext::run($tenant->id, function () use ($tenant): array {
        $agent = User::factory()->create();
        $agent->tenants()->attach($tenant);
        $day = Carbon::today()->subDays(3);

        stint($agent, PresenceStatus::Ready, $day->copy()->setTimeFromTimeString('09:00:00'), $day->copy()->setTimeFromTimeString('10:00:00'));      // 3600
        stint($agent, PresenceStatus::OnCall, $day->copy()->setTimeFromTimeString('10:00:00'), $day->copy()->setTimeFromTimeString('10:30:00'));     // 1800
        stint($agent, PresenceStatus::OnBreak, $day->copy()->setTimeFromTimeString('10:30:00'), $day->copy()->setTimeFromTimeString('11:00:00'));    // 1800
        stint($agent, PresenceStatus::WrappingUp, $day->copy()->setTimeFromTimeString('11:00:00'), $day->copy()->setTimeFromTimeString('11:15:00')); // 900

        return agentDetailFor($agent, $day->toDateString())->daySummary();
    });

    expect($summary['seconds']['ready'])->toBe(3600)
        ->and($summary['seconds']['on_call'])->toBe(1800)
        ->and($summary['seconds']['on_break'])->toBe(1800)
        ->and($summary['seconds']['wrapping_up'])->toBe(900)
        ->and($summary['active'])->toBe(8100) // Active = the four tracked statuses (Offline opens no stint)
        ->and($summary['firstLogin']->format('H:i'))->toBe('09:00')
        ->and($summary['lastActivity']->format('H:i'))->toBe('11:15');
});

// --- AD-4 note: today's still-open stint counts up to now via effectiveEndedAt ---

it('counts today\'s open stint up to now', function () {
    $tenant = Tenant::factory()->create();

    $summary = TenantContext::run($tenant->id, function () use ($tenant): array {
        $agent = User::factory()->create();
        $agent->tenants()->attach($tenant);

        AgentPresence::factory()->forUser($agent)->status(PresenceStatus::Ready)->create(); // fresh heartbeat
        AgentStatusHistory::factory()->forUser($agent)->status(PresenceStatus::Ready)
            ->create(['started_at' => now()->subHour()]); // open (ended_at null)

        return agentDetailFor($agent)->daySummary(); // date null => today
    });

    expect($summary['seconds']['ready'])->toBeGreaterThanOrEqual(3590)
        ->and($summary['seconds']['ready'])->toBeLessThanOrEqual(3610);
});

// --- AD-2 §1: a stint spanning midnight clips to the chosen day ---

it('clips a stint that spans midnight to the chosen day', function () {
    $tenant = Tenant::factory()->create();

    $summary = TenantContext::run($tenant->id, function () use ($tenant): array {
        $agent = User::factory()->create();
        $agent->tenants()->attach($tenant);
        $day = Carbon::today()->subDays(3);

        // 20:00 the day before → 06:00 the chosen day: only 00:00→06:00 belongs to the day.
        stint(
            $agent,
            PresenceStatus::Ready,
            $day->copy()->subDay()->setTimeFromTimeString('20:00:00'),
            $day->copy()->setTimeFromTimeString('06:00:00'),
        );

        return agentDetailFor($agent, $day->toDateString())->daySummary();
    });

    expect($summary['seconds']['ready'])->toBe(21600) // 6h, not 10h
        ->and($summary['firstLogin']->format('H:i'))->toBe('00:00');
});

// --- AD-1 flexibility rule: Outcomes + total calls reuse the counting layer (can't drift) ---

it('reads Outcomes and total calls from the same counting layer as the reports', function () {
    $tenant = Tenant::factory()->create();
    ['alice' => $alice] = seedCallReportFixture($tenant); // Alice: 3 dispositioned calls today

    TenantContext::run($tenant->id, function () use ($alice) {
        $page = agentDetailFor($alice->fresh());
        $filters = new CallReportFilters(from: now()->startOfDay(), to: now()->endOfDay(), agentId: $alice->id);
        $service = app(CallReportService::class);

        expect($page->outcomes())->toEqual($service->dispositionBreakdown($filters))
            ->and($page->daySummary()['totalCalls'])->toBe($service->agentProductivity($filters)[0]['total'])
            ->and($page->daySummary()['totalCalls'])->toBe(3);
    });
});

// --- AD-5: the gate is Call's viewAny — same audience as Call Review / Reporting ---

it('grants access to global staff', function (string $role) {
    $this->actingAs(reportsHcUser($role));
    TenantContext::applyWebRequest(null, crossTenant: true);

    expect(AgentDetail::canAccess())->toBeTrue();
})->with([
    RoleName::SuperAdmin->value,
    RoleName::HcAdmin->value,
    RoleName::OpsManager->value,
]);

it('grants access to per-client auditors (team leader + QC)', function (string $role) {
    $tenant = Tenant::factory()->create();
    $user = clientUserWithRole($tenant, $role);

    TenantContext::run($tenant->id, function () use ($user) {
        $this->actingAs($user->fresh());
        expect(AgentDetail::canAccess())->toBeTrue();
    });
})->with([
    RoleName::TeamLeader->value,
    RoleName::Qc->value,
]);

it('denies access to agent, trainer and client user', function (string $role) {
    $tenant = Tenant::factory()->create();
    $user = clientUserWithRole($tenant, $role);

    TenantContext::run($tenant->id, function () use ($user) {
        $this->actingAs($user->fresh());
        expect(AgentDetail::canAccess())->toBeFalse();
    });
})->with([
    RoleName::Agent->value,
    RoleName::Trainer->value,
    RoleName::ClientUser->value,
]);

// --- AD-5: the tenant wall — a leader can open their own agents, never another client's ---

it('lets a leader open an agent of their own client', function () {
    $tenant = Tenant::factory()->create();
    $leader = clientUserWithRole($tenant, RoleName::TeamLeader->value);
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $this->actingAs($leader->fresh());
    TenantContext::applyWebRequest($tenant->id, crossTenant: false);

    Livewire::test(AgentDetail::class, ['record' => $agent->id])
        ->assertOk()
        ->assertSee($agent->name);
});

it('404s when a leader tries to open another client\'s agent', function () {
    $clientA = Tenant::factory()->create();
    $clientB = Tenant::factory()->create();
    $leaderA = clientUserWithRole($clientA, RoleName::TeamLeader->value);
    $agentB = clientUserWithRole($clientB, RoleName::Agent->value);

    // A real request so the actual route + tenant middleware run: the mount guard
    // 404s rather than rendering an out-of-tenant agent. (Livewire::test swallows
    // the HttpException from mount, so the guard is checked over HTTP.)
    $this->actingAs($leaderA->fresh())
        ->get(AgentDetail::getUrl(['record' => $agentB->id]))
        ->assertNotFound();
});

// --- AD-3: honest labels — the on-call row is "On-call time", never "Talk time" ---

it('labels the on-call sum honestly and never claims talk time', function () {
    $tenant = Tenant::factory()->create();
    $leader = clientUserWithRole($tenant, RoleName::TeamLeader->value);
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $this->actingAs($leader->fresh());
    TenantContext::applyWebRequest($tenant->id, crossTenant: false);

    Livewire::test(AgentDetail::class, ['record' => $agent->id])
        ->assertOk()
        ->assertSee('On-call time')
        ->assertDontSee('Talk time')
        ->assertDontSee('IP address');
});

// --- Entry seams (AD-1) ---

it('carries the agent id on each Live Agents roster row', function () {
    $tenant = Tenant::factory()->create();

    [$agentId, $rowId] = TenantContext::run($tenant->id, function (): array {
        $agent = User::factory()->create();
        AgentPresence::factory()->forUser($agent)->status(PresenceStatus::Ready)->create();
        AgentStatusHistory::factory()->forUser($agent)->status(PresenceStatus::Ready)->create();

        $rows = (new LiveAgents)->roster();

        return [$agent->id, $rows[0]['id']];
    });

    expect($rowId)->toBe($agentId);
});

it('offers the Agent detail action on the Users list to a gated viewer', function () {
    $someone = User::factory()->create(['name' => 'Someone', 'email_verified_at' => now()]);

    $this->actingAs(reportsHcUser(RoleName::SuperAdmin->value));
    TenantContext::applyWebRequest(null, crossTenant: true);

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$someone])
        ->assertTableActionVisible('agent_detail', $someone);
});
