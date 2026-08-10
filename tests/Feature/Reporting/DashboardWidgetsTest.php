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
use App\Filament\Widgets\OperationOverview;
use App\Models\AgentPresence;
use App\Models\Call;
use App\Models\Campaign;
use App\Models\Disposition;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use App\Reporting\ChartPalette;
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
        OperationOverview::class,
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

// --- LB Slice 2: the shared chart palette ---

it('colours the donut charts from the shared palette, one hue per slice', function () {
    $tenant = Tenant::factory()->create();
    seedCallReportFixture($tenant);

    [$disposition, $direction] = TenantContext::run($tenant->id, fn (): array => [
        readWidget(widgetWith(DispositionMixChart::class), 'getData'),
        readWidget(widgetWith(DirectionSplitChart::class), 'getData'),
    ]);

    // Colours come from the shared palette, in fixed order, one per slice — never cycled.
    expect($disposition['datasets'][0]['backgroundColor'])->toBe(ChartPalette::categorical(count($disposition['labels'])))
        ->and($direction['datasets'][0]['backgroundColor'])->toBe(ChartPalette::categorical(2));
});

it('folds dispositions beyond the palette into a single "Other" slice', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function () {
        $campaign = Campaign::factory()->create();
        $agent = User::factory()->create();

        // Ten dispositions with descending call volume, so the ordering is deterministic.
        foreach (range(1, 10) as $rank) {
            $disposition = Disposition::factory()->forCampaign($campaign)->create(['label' => "D{$rank}"]);
            Call::factory()->count(11 - $rank)->forAgent($agent)->create([
                'campaign_id' => $campaign->id,
                'disposition_id' => $disposition->id,
                'created_at' => now(),
            ]);
        }
    });

    $data = TenantContext::run($tenant->id, fn (): array => readWidget(widgetWith(DispositionMixChart::class), 'getData'));

    expect($data['labels'])->toHaveCount(8)                          // 7 real + "Other", never 10
        ->and($data['labels'][7])->toBe('Other')                     // the long thin tail, folded
        ->and($data['datasets'][0]['backgroundColor'])->toHaveCount(8) // one hue per slice, no cycling
        ->and(array_sum($data['datasets'][0]['data']))->toBe(55);    // 10+9+…+1 — no call lost in the fold
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

// --- The counter strip: the size of the operation, above everything else ---

/**
 * A client with a known shape: 2 campaigns switched on + 1 switched off, 4 leads all
 * hung off ONE of those campaigns (Lead::factory() makes its own campaign otherwise,
 * which would inflate the campaign count), 2 users, and 3 board rows of which one has
 * a dead heartbeat.
 */
function seedOperationFixture(Tenant $tenant): void
{
    clientUserWithRole($tenant, RoleName::TeamLeader->value);
    clientUserWithRole($tenant, RoleName::Agent->value);

    TenantContext::run($tenant->id, function (): void {
        $live = Campaign::factory()->create();
        Campaign::factory()->create();
        Campaign::factory()->inactive()->create();

        Lead::factory()->count(4)->forCampaign($live)->create();

        AgentPresence::factory()->count(2)->status(PresenceStatus::Ready)->create();
        AgentPresence::factory()->status(PresenceStatus::Ready)->stale()->create();
    });
}

it('counts campaigns, leads, users and agents on the floor', function () {
    $tenant = Tenant::factory()->create();
    seedOperationFixture($tenant);

    $stats = TenantContext::run(
        $tenant->id,
        fn (): array => readWidget(widgetWith(OperationOverview::class), 'getStats'),
    );

    expect($stats[0]->getValue())->toBe(2)  // switched-on campaigns only — the third is off
        ->and($stats[1]->getValue())->toBe(4)
        ->and($stats[2]->getValue())->toBe(2)
        ->and($stats[3]->getValue())->toBe(2); // the stale heartbeat reads Offline, not logged in
});

it('walls the counter strip to the current client', function () {
    $clientA = Tenant::factory()->create();
    $clientB = Tenant::factory()->create();
    seedOperationFixture($clientA);
    seedOperationFixture($clientB);

    $stats = TenantContext::run(
        $clientA->id,
        fn (): array => readWidget(widgetWith(OperationOverview::class), 'getStats'),
    );

    // A's own numbers, never doubled by B's identical fixture — including Users,
    // which carries no tenant wall of its own and is scoped by membership instead.
    expect(array_map(fn ($stat) => $stat->getValue(), $stats))->toBe([2, 4, 2, 2]);
});

it('narrows the counter strip to one client for global staff', function () {
    $clientA = Tenant::factory()->create();
    $clientB = Tenant::factory()->create();
    seedOperationFixture($clientA);
    seedOperationFixture($clientB);

    TenantContext::applyWebRequest(null, crossTenant: true);

    $stats = readWidget(widgetWith(OperationOverview::class, ['clientId' => $clientA->id]), 'getStats');

    expect(array_map(fn ($stat) => $stat->getValue(), $stats))->toBe([2, 4, 2, 2]);
});

it('rolls the counter strip across every client when global staff pick none', function () {
    $clientA = Tenant::factory()->create();
    $clientB = Tenant::factory()->create();
    seedOperationFixture($clientA);
    seedOperationFixture($clientB);

    // HC's own staff belong to no client, so they must not swell the Users count —
    // the strip measures the clients' operation, not the people watching it.
    $this->actingAs(reportsHcUser(RoleName::HcAdmin->value));
    TenantContext::applyWebRequest(null, crossTenant: true);

    $stats = readWidget(widgetWith(OperationOverview::class), 'getStats');

    expect(array_map(fn ($stat) => $stat->getValue(), $stats))->toBe([4, 8, 4, 4]);
});

it('ignores the date range — the strip is inventory, not history', function () {
    $tenant = Tenant::factory()->create();
    seedOperationFixture($tenant);

    // A window with nothing in it. The tiles below the strip would empty out; these
    // four must not move, because none of them is a thing that happened in a period.
    $stats = TenantContext::run($tenant->id, fn (): array => readWidget(
        widgetWith(OperationOverview::class, [
            'startDate' => now()->subYears(2)->toDateString(),
            'endDate' => now()->subYears(2)->addDay()->toDateString(),
        ]),
        'getStats',
    ));

    expect(array_map(fn ($stat) => $stat->getValue(), $stats))->toBe([2, 4, 2, 2]);
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
