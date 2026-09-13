<?php

use App\Enums\CallOutcome;
use App\Models\Call;
use App\Models\Campaign;
use App\Models\Disposition;
use App\Models\Tenant;
use App\Models\User;
use App\Reporting\CallReportFilters;
use App\Reporting\CallReportService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

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

// --- apr.md AP-6: talk, hold, wrap and Average Handle Time ---

/**
 * A handled call with known moments: answered now, hung up after $talk + $hold
 * seconds of line time, Done clicked $wrap seconds later.
 */
function handledCall(User $agent, int $talk, int $hold, int $wrap): Call
{
    $answeredAt = now()->subMinutes(30);

    return Call::factory()->forAgent($agent)->create([
        'answered_at' => $answeredAt,
        'ended_at' => $answeredAt->copy()->addSeconds($talk + $hold),
        'hold_seconds' => $hold,
        'created_at' => $answeredAt->copy()->addSeconds($talk + $hold + $wrap),
    ]);
}

it('sums talk, hold and wrap and divides the handle time by answered calls', function () {
    $tenant = Tenant::factory()->create();

    $rows = TenantContext::run($tenant->id, function (): array {
        $agent = User::factory()->create(['name' => 'Alice']);

        handledCall($agent, talk: 100, hold: 20, wrap: 30);  // handled: 150
        handledCall($agent, talk: 200, hold: 0, wrap: 10);   // handled: 210

        // Nobody answered this one: no talk, no hold, no Done click of its own. It
        // counts on the Total column and NOWHERE in the handle time (AP-6).
        Call::factory()->forAgent($agent)->create(['answered_at' => null, 'created_at' => now()]);

        return (new CallReportService)->agentProductivity(new CallReportFilters);
    });

    expect($rows[0])->toMatchArray([
        'agent' => 'Alice',
        'total' => 3,
        'answered' => 2,
        'talk_seconds' => 300,
        'hold_seconds' => 20,
        'wrap_seconds' => 40,
        // (300 + 20 + 40) / 2 answered = 180. Over all 3 calls it would read 120.
        'aht_seconds' => 180,
    ]);
});

it('counts a held call once: talk drops the hold, the handle time keeps it', function () {
    $tenant = Tenant::factory()->create();

    $rows = TenantContext::run($tenant->id, function (): array {
        $agent = User::factory()->create();

        // 10 minutes on the line, 4 of them music. Talk is the 6 minutes of
        // conversation; the handle time is the whole 10 plus the wrap (hold.md H-3).
        handledCall($agent, talk: 360, hold: 240, wrap: 60);

        return (new CallReportService)->agentProductivity(new CallReportFilters);
    });

    expect($rows[0])->toMatchArray([
        'talk_seconds' => 360,
        'hold_seconds' => 240,
        'wrap_seconds' => 60,
        'aht_seconds' => 660,
    ]);
});

it('leaves the handle time blank, never zero, for an agent who answered nothing', function () {
    $tenant = Tenant::factory()->create();

    $rows = TenantContext::run($tenant->id, function (): array {
        $agent = User::factory()->create();

        Call::factory()->forAgent($agent)->create(['answered_at' => null, 'created_at' => now()]);

        return (new CallReportService)->agentProductivity(new CallReportFilters);
    });

    expect($rows[0]['answered'])->toBe(0)
        ->and($rows[0]['talk_seconds'])->toBe(0)
        ->and($rows[0]['aht_seconds'])->toBeNull();
});

/**
 * 🔴 THE WELD (AP-3/AP-6). The time sums run in the database for speed, which is the
 * ONE second copy of an arithmetic rule this build accepts. This test runs both
 * engines over the same calls and fails the moment either side is edited alone —
 * without it the two versions drift, which is exactly how `duration_seconds` came to
 * mean two things on two screens and had to be retired (CT-4).
 */
it('welds the database time sums to the PHP accessors', function () {
    $tenant = Tenant::factory()->create();

    [$rows, $calls] = TenantContext::run($tenant->id, function (): array {
        $agent = User::factory()->create();

        handledCall($agent, talk: 137, hold: 43, wrap: 29);
        handledCall($agent, talk: 512, hold: 7, wrap: 300);

        // A call recorded BEFORE Hold shipped carries no hold information at all. This
        // is the row that catches a missing COALESCE: without it the database returns
        // NULL for this call and the whole SUM goes null.
        $preHold = handledCall($agent, talk: 90, hold: 0, wrap: 15);
        $preHold->forceFill(['hold_seconds' => null])->save();

        return [
            (new CallReportService)->agentProductivity(new CallReportFilters),
            Call::query()->get(),
        ];
    });

    expect($rows[0]['talk_seconds'])->toBe((int) $calls->sum(fn (Call $call): int => $call->talkedSeconds() ?? 0))
        ->and($rows[0]['wrap_seconds'])->toBe((int) $calls->sum(fn (Call $call): int => $call->wrappedSeconds() ?? 0))
        ->and($rows[0]['hold_seconds'])->toBe((int) $calls->sum(fn (Call $call): int => (int) $call->hold_seconds))
        ->and($rows[0]['talk_seconds'])->toBe(739);
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

it('merges same-named dispositions from different campaigns into one row', function () {
    $tenant = Tenant::factory()->create();

    $campaigns = TenantContext::run($tenant->id, function (): array {
        $agent = User::factory()->create();
        $first = Campaign::factory()->create();
        $second = Campaign::factory()->create();

        // Dispositions are per-campaign, so each campaign carries its OWN "No answer"
        // row: same label, different id. Grouping by id split these into two slices
        // that read identically on the dashboard donut and the summary report, and
        // hid the real total for the outcome.
        $firstNoAnswer = Disposition::factory()->forCampaign($first)->create([
            'label' => 'No answer', 'is_contact' => false, 'is_sale' => false,
        ]);
        $secondNoAnswer = Disposition::factory()->forCampaign($second)->create([
            'label' => 'No answer', 'is_contact' => false, 'is_sale' => false,
        ]);

        Call::factory()->forAgent($agent)->count(2)->create([
            'campaign_id' => $first->id, 'disposition_id' => $firstNoAnswer->id, 'created_at' => now(),
        ]);
        Call::factory()->forAgent($agent)->create([
            'campaign_id' => $second->id, 'disposition_id' => $secondNoAnswer->id, 'created_at' => now(),
        ]);

        return ['first' => $first];
    });

    $service = new CallReportService;

    // Across all campaigns: ONE row carrying the true total, not two rows of 2 and 1.
    $all = TenantContext::run($tenant->id, fn (): array => $service->dispositionBreakdown(new CallReportFilters));

    expect($all)->toHaveCount(1)
        ->and($all[0])->toMatchArray(['label' => 'No answer', 'count' => 3, 'percentage' => 100.0]);

    // Narrowed to one campaign, the merge does not over-count: only that campaign's 2.
    $narrowed = TenantContext::run($tenant->id, fn (): array => $service->dispositionBreakdown(
        CallReportFilters::fromArray(['campaignId' => $campaigns['first']->id]),
    ));

    expect($narrowed)->toHaveCount(1)
        ->and($narrowed[0])->toMatchArray(['label' => 'No answer', 'count' => 2]);
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

// --- DIAL-1 DP-12 / DP-12a: the dialer's abandoned rate and voicemail share ---

/**
 * A row the progressive dialer placed, filed at a moment on the client's own clock.
 *
 * @param  array<string, mixed>  $attributes
 */
function dialledCall(Campaign $campaign, string $ticket, string $atIndia, array $attributes = []): Call
{
    return Call::factory()->create([
        'was_dialled' => true,
        'campaign_id' => $campaign->id,
        'correlation_id' => $ticket,
        'created_at' => Carbon::parse($atIndia, 'Asia/Kolkata')->utc(),
        ...$attributes,
    ]);
}

it('counts the abandoned rate over dials the dialer placed, per client day, with a row per campaign', function () {
    $tenant = Tenant::factory()->create();

    [$rows, $narrowedToOneAgent] = TenantContext::run($tenant->id, function (): array {
        $agent = User::factory()->create();
        $closer = User::factory()->create();
        $cod = Campaign::factory()->create(['name' => 'COD']);
        $ndr = Campaign::factory()->create(['name' => 'NDR']);
        // Relabelled on purpose: the share keys on the code, and a client may call it anything.
        $voicemail = Disposition::factory()->create([
            'campaign_id' => $cod->id, 'code' => Disposition::VOICEMAIL_CODE, 'label' => 'Machine',
        ]);

        dialledCall($cod, (string) Str::uuid(), '2026-09-11 10:00', ['outcome' => CallOutcome::NoAnswer]);   // rang out
        dialledCall($cod, (string) Str::uuid(), '2026-09-11 10:05', ['outcome' => CallOutcome::Abandoned]);  // hung up holding
        dialledCall($cod, (string) Str::uuid(), '2026-09-11 10:10', ['outcome' => CallOutcome::Abandoned]);  // the hold limit
        dialledCall($cod, (string) Str::uuid(), '2026-09-11 10:15', ['agent_id' => $agent->id, 'disposition_id' => $voicemail->id]);

        // F26: answered on NDR and transferred — two agents, two rows, ONE dial.
        $transferred = (string) Str::uuid();
        dialledCall($ndr, $transferred, '2026-09-11 11:00', ['agent_id' => $agent->id]);
        dialledCall($ndr, $transferred, '2026-09-11 11:03', ['agent_id' => $closer->id]);

        // Neither of these was placed by the dialer: an agent's own dial and an inbound abandon.
        $noon = Carbon::parse('2026-09-11 12:00', 'Asia/Kolkata')->utc();
        Call::factory()->forAgent($agent)->create(['campaign_id' => $cod->id, 'outcome' => CallOutcome::NoAnswer, 'created_at' => $noon]);
        Call::factory()->inbound()->create(['campaign_id' => $cod->id, 'outcome' => CallOutcome::Abandoned, 'created_at' => $noon]);

        // 00:30 on the 12th in India is still the 11th in UTC. It is the client's 12th.
        dialledCall($cod, (string) Str::uuid(), '2026-09-12 00:30', ['outcome' => CallOutcome::Abandoned]);

        $service = new CallReportService;

        return [
            $service->dialerByDay(new CallReportFilters),
            $service->dialerByDay(new CallReportFilters(agentId: $closer->id)),
        ];
    });

    expect($rows)->toHaveCount(5)
        ->and($rows[0])->toMatchArray(['date' => '2026-09-11', 'is_total' => true, 'dials' => 5, 'abandoned' => 2, 'abandoned_rate' => 40.0, 'voicemail' => null])
        ->and($rows[1])->toMatchArray(['date' => '2026-09-11', 'campaign' => 'COD', 'dials' => 4, 'abandoned' => 2, 'abandoned_rate' => 50.0, 'answered' => 1, 'voicemail' => 1])
        // No VOICEMAIL disposition on NDR, so no share: a dash, never 0.
        ->and($rows[2])->toMatchArray(['date' => '2026-09-11', 'campaign' => 'NDR', 'dials' => 1, 'abandoned' => 0, 'answered' => 1, 'voicemail' => null])
        ->and($rows[3])->toMatchArray(['date' => '2026-09-12', 'is_total' => true, 'dials' => 1, 'abandoned' => 1, 'abandoned_rate' => 100.0])
        ->and($rows[4])->toMatchArray(['date' => '2026-09-12', 'campaign' => 'COD', 'answered' => 0])
        // Strict: toMatchArray compares loosely, and null == 0 — the very difference the dash is.
        ->and($rows[2]['voicemail'])->toBeNull()
        ->and($rows[4]['voicemail'])->toBe(0)
        // A ring-out has no agent, so an agent filter would show a dialer that never abandons.
        ->and($narrowedToOneAgent)->toBe($rows);
});

it('gives each client its own day total, never one rate across clients', function () {
    $acme = Tenant::factory()->create(['name' => 'Acme']);
    $zeta = Tenant::factory()->create(['name' => 'Zeta']);

    TenantContext::run($acme->id, fn () => dialledCall(Campaign::factory()->create(), (string) Str::uuid(), '2026-09-11 10:00', ['outcome' => CallOutcome::Abandoned]));
    TenantContext::run($zeta->id, function (): void {
        $campaign = Campaign::factory()->create();
        dialledCall($campaign, (string) Str::uuid(), '2026-09-11 10:00', ['outcome' => CallOutcome::Abandoned]);
        dialledCall($campaign, (string) Str::uuid(), '2026-09-11 10:05', ['outcome' => CallOutcome::NoAnswer]);
    });

    $totals = collect(TenantContext::cross(fn (): array => (new CallReportService)->dialerByDay(new CallReportFilters)))
        ->where('is_total', true);

    expect($totals)->toHaveCount(2)
        ->and($totals->firstWhere('client', 'Acme'))->toMatchArray(['dials' => 1, 'abandoned' => 1, 'abandoned_rate' => 100.0])
        ->and($totals->firstWhere('client', 'Zeta'))->toMatchArray(['dials' => 2, 'abandoned' => 1, 'abandoned_rate' => 50.0]);
});
