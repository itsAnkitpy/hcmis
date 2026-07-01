<?php

use App\Enums\PresenceStatus;
use App\Enums\RoleName;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\CallsByAgentChart;
use App\Filament\Widgets\CallsPerDayChart;
use App\Filament\Widgets\CallStatsOverview;
use App\Filament\Widgets\DirectionSplitChart;
use App\Filament\Widgets\DispositionMixChart;
use App\Filament\Widgets\LiveAvailabilitySnapshot;
use App\Models\AgentPresence;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::resetWebRequest();
    TenantContext::forget();
});

// reportsHcUser() + clientUserWithRole() + seedCallReportFixture() live in tests/Pest.php.

/**
 * The dashboard's widget classes, all sharing Call Review's view gate.
 *
 * @return array<int, class-string>
 */
function dashboardWidgets(): array
{
    return [
        CallStatsOverview::class,
        CallsPerDayChart::class,
        DispositionMixChart::class,
        CallsByAgentChart::class,
        DirectionSplitChart::class,
        LiveAvailabilitySnapshot::class,
    ];
}

/**
 * A fresh widget with the given (raw) shared page-filter state, as Filament would
 * hand it down from the dashboard.
 *
 * @param  class-string  $class
 * @param  array<string, mixed>  $pageFilters
 */
function widgetWith(string $class, array $pageFilters = []): object
{
    $widget = new $class;
    $widget->pageFilters = $pageFilters;

    return $widget;
}

/**
 * Read a protected widget method (getStats / getData / statusCounts) from the outside
 * — a regular closure rebound onto the widget can reach protected scope. Keeps the
 * widgets' data methods protected (no test-only public surface).
 */
function readWidget(object $widget, string $method): mixed
{
    return (function () use ($method) {
        return $this->{$method}();
    })->call($widget);
}

// --- RP-4: every dashboard widget shares Call Review's gate ---

it('shows every dashboard widget to global staff', function () {
    $this->actingAs(reportsHcUser(RoleName::HcAdmin->value));
    TenantContext::applyWebRequest(null, crossTenant: true);

    foreach (dashboardWidgets() as $widget) {
        expect($widget::canView())->toBeTrue();
    }
});

it('shows every dashboard widget to a per-client auditor', function (string $role) {
    $tenant = Tenant::factory()->create();
    $user = clientUserWithRole($tenant, $role);

    TenantContext::run($tenant->id, function () use ($user) {
        $this->actingAs($user->fresh());
        foreach (dashboardWidgets() as $widget) {
            expect($widget::canView())->toBeTrue();
        }
    });
})->with([RoleName::TeamLeader->value, RoleName::Qc->value]);

it('hides every dashboard widget from agents, trainers and client users', function (string $role) {
    $tenant = Tenant::factory()->create();
    $user = clientUserWithRole($tenant, $role);

    TenantContext::run($tenant->id, function () use ($user) {
        $this->actingAs($user->fresh());
        foreach (dashboardWidgets() as $widget) {
            expect($widget::canView())->toBeFalse();
        }
    });
})->with([RoleName::Agent->value, RoleName::Trainer->value, RoleName::ClientUser->value]);

// --- Stat tiles + charts build their datasets from the shared counting layer ---

it('builds the stat tiles from the counting layer', function () {
    $tenant = Tenant::factory()->create();
    seedCallReportFixture($tenant);

    $stats = TenantContext::run(
        $tenant->id,
        fn (): array => readWidget(widgetWith(CallStatsOverview::class), 'getStats'),
    );

    // Total 5 · Contacts 3 · Sales 1 · Recording coverage 20% — the fixture's totals.
    $values = array_map(fn ($stat) => $stat->getValue(), $stats);
    expect($values)->toBe([5, 3, 1, '20%']);
});

it('builds the calls-by-agent chart from the counting layer, busiest first', function () {
    $tenant = Tenant::factory()->create();
    seedCallReportFixture($tenant);

    $data = TenantContext::run(
        $tenant->id,
        fn (): array => readWidget(widgetWith(CallsByAgentChart::class), 'getData'),
    );

    expect($data['labels'])->toBe(['Alice', 'Bob'])
        ->and($data['datasets'][0]['data'])->toBe([3, 2]);
});

it('builds the disposition mix chart from the counting layer', function () {
    $tenant = Tenant::factory()->create();
    seedCallReportFixture($tenant);

    $data = TenantContext::run(
        $tenant->id,
        fn (): array => readWidget(widgetWith(DispositionMixChart::class), 'getData'),
    );

    // Most-used disposition first; only the 4 dispositioned calls appear.
    expect($data['labels'][0])->toBe('Interested')
        ->and(array_sum($data['datasets'][0]['data']))->toBe(4);
});

it('builds the direction split chart from the period totals', function () {
    $tenant = Tenant::factory()->create();
    seedCallReportFixture($tenant);

    $data = TenantContext::run(
        $tenant->id,
        fn (): array => readWidget(widgetWith(DirectionSplitChart::class), 'getData'),
    );

    expect($data['labels'])->toBe(['Inbound', 'Outbound'])
        ->and($data['datasets'][0]['data'])->toBe([2, 3]);
});

// --- The shared page filter flows through the guard-parse into the widgets ---

it('recomputes a chart when the shared page filter narrows the range', function () {
    $tenant = Tenant::factory()->create();
    seedCallReportFixture($tenant);

    $data = TenantContext::run($tenant->id, fn (): array => readWidget(
        // A future start date excludes every "today" call.
        widgetWith(CallsPerDayChart::class, ['startDate' => now()->addDay()->toDateString()]),
        'getData',
    ));

    expect($data['labels'])->toBe([])
        ->and($data['datasets'][0]['data'])->toBe([]);
});

// --- RP-4: the tenant wall holds on a widget ---

it('walls a widget to the current client', function () {
    $clientA = Tenant::factory()->create();
    $clientB = Tenant::factory()->create();
    seedCallReportFixture($clientA);
    seedCallReportFixture($clientB);

    $stats = TenantContext::run(
        $clientA->id,
        fn (): array => readWidget(widgetWith(CallStatsOverview::class), 'getStats'),
    );

    // Only A's five calls — B's identical fixture never leaks in.
    expect($stats[0]->getValue())->toBe(5);
});

// --- RP-6: the live tile reads effectiveStatus() — a stale heartbeat is Offline ---

it('counts a stale heartbeat as Offline, not Ready, on the live tile', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function () {
        AgentPresence::factory()->status(PresenceStatus::Ready)->create();           // fresh → Ready
        AgentPresence::factory()->status(PresenceStatus::Ready)->stale()->create();  // stale → Offline
        AgentPresence::factory()->status(PresenceStatus::OnBreak)->create();         // fresh → On break
    });

    $counts = TenantContext::run(
        $tenant->id,
        fn (): array => readWidget(widgetWith(LiveAvailabilitySnapshot::class), 'statusCounts'),
    );

    expect($counts[PresenceStatus::Ready->value])->toBe(1)
        ->and($counts[PresenceStatus::Offline->value])->toBe(1)
        ->and($counts[PresenceStatus::OnBreak->value])->toBe(1)
        ->and($counts[PresenceStatus::OnCall->value])->toBe(0)
        ->and($counts[PresenceStatus::WrappingUp->value])->toBe(0);
});

it('narrows the live tile to one client for global staff', function () {
    $clientA = Tenant::factory()->create();
    $clientB = Tenant::factory()->create();
    TenantContext::run($clientA->id, fn () => AgentPresence::factory()->count(2)->status(PresenceStatus::Ready)->create());
    TenantContext::run($clientB->id, fn () => AgentPresence::factory()->count(3)->status(PresenceStatus::Ready)->create());

    // Global cross-tenant view, narrowed to client A via the shared filter.
    TenantContext::applyWebRequest(null, crossTenant: true);

    $counts = readWidget(
        widgetWith(LiveAvailabilitySnapshot::class, ['clientId' => $clientA->id]),
        'statusCounts',
    );

    expect($counts[PresenceStatus::Ready->value])->toBe(2); // A's two, never B's three
});

// --- The customized dashboard mounts with its widgets for a permitted user ---

it('renders the operations dashboard for a permitted user', function () {
    $tenant = Tenant::factory()->create();
    seedCallReportFixture($tenant);
    $teamLeader = clientUserWithRole($tenant, RoleName::TeamLeader->value);

    $this->actingAs($teamLeader);
    TenantContext::applyWebRequest($tenant->id, crossTenant: false);

    Livewire::test(Dashboard::class)->assertOk();
});

it('exposes exactly the reporting widgets on the dashboard', function () {
    expect((new Dashboard)->getWidgets())->toBe(dashboardWidgets());
});
