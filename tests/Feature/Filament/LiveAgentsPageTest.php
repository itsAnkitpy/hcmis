<?php

use App\Enums\PresenceStatus;
use App\Enums\RoleName;
use App\Filament\Pages\LiveAgents;
use App\Models\AgentPresence;
use App\Models\AgentStatusHistory;
use App\Models\BreakCategory;
use App\Models\Call;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::resetWebRequest();
    TenantContext::forget();
});

/**
 * A working agent: a board row + a matching open stint, so the roster reads a real
 * time-in-status and (for a break) the category detail. Call inside a tenant context.
 */
function floorAgent(string $name, PresenceStatus $status, int $sinceMinutes = 5, ?BreakCategory $break = null, bool $stale = false): User
{
    $user = User::factory()->create(['name' => $name]);

    $presence = AgentPresence::factory()->forUser($user)->status($status);
    $presence = $stale ? $presence->stale() : $presence;
    $presence->create();

    $stint = AgentStatusHistory::factory()->forUser($user);
    $stint = $break !== null ? $stint->onBreak($break) : $stint->status($status);
    $stint->create(['started_at' => now()->subMinutes($sinceMinutes)]);

    return $user;
}

// --- LB gate: same audience as the dashboard/reports ---

it('lets a reporting role onto the board and keeps agents out', function () {
    $tenant = Tenant::factory()->create();
    $leader = clientUserWithRole($tenant, RoleName::TeamLeader->value);
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    TenantContext::run($tenant->id, function () use ($leader, $agent) {
        $this->actingAs($leader->fresh());
        expect(LiveAgents::canAccess())->toBeTrue();

        $this->actingAs($agent->fresh());
        expect(LiveAgents::canAccess())->toBeFalse();
    });
});

// --- LB-1: only currently-active agents; offline + stale drop off ---

it('lists only currently-active agents, hiding offline and stale ones', function () {
    $tenant = Tenant::factory()->create();

    $rows = TenantContext::run($tenant->id, function (): array {
        floorAgent('Ready Rita', PresenceStatus::Ready);
        floorAgent('Calling Carl', PresenceStatus::OnCall);
        floorAgent('Offline Omar', PresenceStatus::Offline);          // logged out
        floorAgent('Stale Sam', PresenceStatus::Ready, stale: true);  // heartbeat gone quiet → Offline

        return (new LiveAgents)->roster();
    });

    $names = array_column($rows, 'name');

    expect($rows)->toHaveCount(2)
        ->and($names)->toContain('Ready Rita', 'Calling Carl')
        ->and($names)->not->toContain('Offline Omar', 'Stale Sam');
});

// --- LB-3: break detail + the red overstay flag (computed, never stored — BK-4) ---

it('shows break detail and flags only the break that is over its limit', function () {
    $tenant = Tenant::factory()->create();

    $rows = TenantContext::run($tenant->id, function (): array {
        $lunch = BreakCategory::factory()->withLimit(30)->create(['code' => 'LUNCH', 'label' => 'Lunch']);
        floorAgent('Over Olga', PresenceStatus::OnBreak, sinceMinutes: 45, break: $lunch);  // 45 > 30 → over
        floorAgent('Fresh Fred', PresenceStatus::OnBreak, sinceMinutes: 10, break: $lunch); // 10 < 30 → fine

        return (new LiveAgents)->roster();
    });

    $byName = collect($rows)->keyBy('name');

    expect($byName['Over Olga']['breakCategory'])->toBe('Lunch')
        ->and($byName['Over Olga']['limitMinutes'])->toBe(30)
        ->and($byName['Over Olga']['overstayed'])->toBeTrue()
        ->and($byName['Fresh Fred']['overstayed'])->toBeFalse();
});

// --- LB-3: calls today from the shared counting layer (never disagrees with reports) ---

it("counts each agent's calls handled today, today only", function () {
    $tenant = Tenant::factory()->create();

    $rows = TenantContext::run($tenant->id, function (): array {
        $rita = floorAgent('Ready Rita', PresenceStatus::Ready);
        Call::factory()->count(3)->forAgent($rita)->create(['created_at' => now()]);
        Call::factory()->forAgent($rita)->create(['created_at' => now()->subDays(2)]); // not today

        return (new LiveAgents)->roster();
    });

    expect($rows[0]['callsToday'])->toBe(3);
});

// --- LB-1 ordering: most-actionable first ---

it('orders on-a-call first and on-break last', function () {
    $tenant = Tenant::factory()->create();

    $rows = TenantContext::run($tenant->id, function (): array {
        $tea = BreakCategory::factory()->withLimit(15)->create(['code' => 'TEA', 'label' => 'Tea']);
        floorAgent('Breaker', PresenceStatus::OnBreak, break: $tea);
        floorAgent('Reader', PresenceStatus::Ready);
        floorAgent('Caller', PresenceStatus::OnCall);

        return (new LiveAgents)->roster();
    });

    expect(array_column($rows, 'name'))->toBe(['Caller', 'Reader', 'Breaker']);
});

// --- The tenant wall holds ---

it("never shows another client's agents", function () {
    $clientA = Tenant::factory()->create();
    $clientB = Tenant::factory()->create();

    TenantContext::run($clientA->id, fn () => floorAgent('A Agent', PresenceStatus::Ready));
    TenantContext::run($clientB->id, fn () => floorAgent('B Agent', PresenceStatus::Ready));

    $rows = TenantContext::run($clientA->id, fn (): array => (new LiveAgents)->roster());

    expect(array_column($rows, 'name'))->toBe(['A Agent']);
});

// --- A real render: rows, status, and the over-the-limit marker reach the page ---

it('renders the live board with the head count and the over-the-limit marker', function () {
    $tenant = Tenant::factory()->create();
    $leader = clientUserWithRole($tenant, RoleName::TeamLeader->value);

    TenantContext::run($tenant->id, function () {
        $lunch = BreakCategory::factory()->withLimit(30)->create(['code' => 'LUNCH', 'label' => 'Lunch']);
        floorAgent('Asha', PresenceStatus::OnBreak, sinceMinutes: 45, break: $lunch);
        floorAgent('Ravi', PresenceStatus::Ready);
    });

    $this->actingAs($leader);
    TenantContext::applyWebRequest($tenant->id, crossTenant: false);

    Livewire::test(LiveAgents::class)
        ->assertOk()
        ->assertSee('Asha')
        ->assertSee('Ravi')
        ->assertSee('Lunch')
        ->assertSee('over the limit')
        ->assertSee('on the floor');
});
