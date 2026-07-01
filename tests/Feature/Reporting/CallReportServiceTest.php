<?php

use App\Models\Tenant;
use App\Reporting\CallReportFilters;
use App\Reporting\CallReportService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::resetWebRequest();
    TenantContext::forget();
});

// The seedCallReportFixture() helper lives in tests/Pest.php (shared with the page tests).

// --- RP-1/RP-2: the counting layer totals against a known fixture ---

it('computes period totals from the populated v1 fields', function () {
    $tenant = Tenant::factory()->create();
    seedCallReportFixture($tenant);

    $totals = TenantContext::run(
        $tenant->id,
        fn (): array => (new CallReportService)->totals(new CallReportFilters),
    );

    expect($totals)->toMatchArray([
        'total' => 5,
        'inbound' => 2,
        'outbound' => 3,
        'contacts' => 3,
        'sales' => 1,
        'with_recording' => 1,
        'recording_coverage' => 20.0,
    ]);
});

it('computes per-agent productivity rows, busiest first', function () {
    $tenant = Tenant::factory()->create();
    seedCallReportFixture($tenant);

    $rows = TenantContext::run(
        $tenant->id,
        fn (): array => (new CallReportService)->agentProductivity(new CallReportFilters),
    );

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toMatchArray([
            'agent' => 'Alice', 'total' => 3, 'inbound' => 1, 'outbound' => 2,
            'contacts' => 2, 'sales' => 1, 'no_answer' => 1, 'with_recording' => 1, 'contact_rate' => 66.7,
        ])
        ->and($rows[1])->toMatchArray([
            'agent' => 'Bob', 'total' => 2, 'inbound' => 1, 'outbound' => 1,
            'contacts' => 1, 'sales' => 0, 'no_answer' => 1, 'with_recording' => 0, 'contact_rate' => 50.0,
        ]);
});

it('breaks calls down by disposition as a share of all calls in range', function () {
    $tenant = Tenant::factory()->create();
    seedCallReportFixture($tenant);

    $rows = TenantContext::run(
        $tenant->id,
        fn (): array => (new CallReportService)->dispositionBreakdown(new CallReportFilters),
    );

    // Only the 4 dispositioned calls appear; % is of the grand total of 5.
    expect($rows)->toHaveCount(3)
        ->and(collect($rows)->firstWhere('label', 'Interested'))->toMatchArray(['count' => 2, 'percentage' => 40.0])
        ->and(collect($rows)->firstWhere('label', 'Sold'))->toMatchArray(['count' => 1, 'percentage' => 20.0])
        ->and(collect($rows)->firstWhere('label', 'No answer'))->toMatchArray(['count' => 1, 'percentage' => 20.0]);
});

it('buckets calls by day', function () {
    $tenant = Tenant::factory()->create();
    seedCallReportFixture($tenant);

    $rows = TenantContext::run(
        $tenant->id,
        fn (): array => (new CallReportService)->callsByDay(new CallReportFilters),
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray([
            'date' => now()->toDateString(),
            'total' => 5, 'inbound' => 2, 'outbound' => 3, 'contacts' => 3, 'sales' => 1,
        ]);
});

// --- Filters narrow correctly ---

it('narrows by direction, campaign, and date range', function () {
    $tenant = Tenant::factory()->create();
    seedCallReportFixture($tenant);

    $service = new CallReportService;

    // Direction filter: only the 3 outbound calls.
    $outbound = TenantContext::run($tenant->id, fn (): array => $service->totals(
        CallReportFilters::fromArray(['direction' => 'outbound']),
    ));
    expect($outbound['total'])->toBe(3);

    // A date window in the future excludes everything (all seeded "today").
    $future = TenantContext::run($tenant->id, fn (): array => $service->totals(
        CallReportFilters::fromArray(['startDate' => now()->addDay()->toDateString()]),
    ));
    expect($future['total'])->toBe(0);
});

// --- RP-4: the tenant wall — client A never sees client B ---

it('returns only the current client rows (the tenant wall)', function () {
    $clientA = Tenant::factory()->create();
    $clientB = Tenant::factory()->create();

    seedCallReportFixture($clientA);
    seedCallReportFixture($clientB);

    $totalsA = TenantContext::run(
        $clientA->id,
        fn (): array => (new CallReportService)->totals(new CallReportFilters),
    );

    // A's five calls only — B's identical fixture never leaks in.
    expect($totalsA['total'])->toBe(5);
});

// --- RP-4 (S63): global-staff client narrowing rides ON TOP of the wall ---

it('rolls up every client for global staff, and narrows to one when a client is picked', function () {
    $clientA = Tenant::factory()->create();
    $clientB = Tenant::factory()->create();
    seedCallReportFixture($clientA);
    seedCallReportFixture($clientB);

    // Cross-tenant posture — the global HC staff view (same as Call Review).
    TenantContext::applyWebRequest(null, crossTenant: true);
    $service = new CallReportService;

    // No client picked → both clients roll up (5 + 5).
    expect($service->totals(new CallReportFilters)['total'])->toBe(10);

    // Client A picked → only A's five.
    expect($service->totals(CallReportFilters::fromArray(['clientId' => $clientA->id]))['total'])->toBe(5);
});

it('holds the tenant wall even when a per-client user injects another client id', function () {
    $clientA = Tenant::factory()->create();
    $clientB = Tenant::factory()->create();
    seedCallReportFixture($clientA);
    seedCallReportFixture($clientB);

    // A per-client user (pinned to A) sends B's id in the filter. The wall pins to
    // A, so "A AND B" is empty — B's numbers never leak; the injection is inert.
    $totals = TenantContext::run(
        $clientA->id,
        fn (): array => (new CallReportService)->totals(CallReportFilters::fromArray(['clientId' => $clientB->id])),
    );

    expect($totals['total'])->toBe(0);
});

// --- RP-2 honesty: an empty range never divides by zero ---

it('reports zero coverage on an empty range without erroring', function () {
    $tenant = Tenant::factory()->create();

    $totals = TenantContext::run(
        $tenant->id,
        fn (): array => (new CallReportService)->totals(new CallReportFilters),
    );

    expect($totals['total'])->toBe(0)
        ->and($totals['recording_coverage'])->toBe(0.0);
});
