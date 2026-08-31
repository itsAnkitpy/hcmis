<?php

use App\Enums\CallDirection;
use App\Enums\CallOutcome;
use App\Enums\RoleName;
use App\Models\Call;
use App\Models\Campaign;
use App\Models\Disposition;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use App\Reporting\CallExportRows;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::resetWebRequest();
    TenantContext::forget();
});

/**
 * Call Export step 2 (call-export.md CE-4 + CE-5a) — the real menu and the one filtered
 * query. The guards around it are covered in CallExportRouteTest; these tests are about
 * WHICH calls come out and WHAT each row says.
 */

/**
 * Run the exporter inside a client's context and return the rows as arrays.
 *
 * @param  array<string, mixed>  $filters
 * @return array<int, array<int, string|int|null>>
 */
function exportRows(Tenant $tenant, array $filters = []): array
{
    return TenantContext::run($tenant->id, fn (): array => iterator_to_array(
        (new CallExportRows($filters))->rows(),
        preserve_keys: false,
    ));
}

/** The heading list the export writes for the current posture. */
function exportHeadings(Tenant $tenant, array $filters = []): array
{
    return TenantContext::run($tenant->id, fn (): array => (new CallExportRows($filters))->headings());
}

/**
 * The value under a named heading, for one row. Takes the same filters the row was
 * produced with, because CE-12's custom columns exist only under a campaign filter — a
 * heading list built without them would not contain the column being asked for.
 *
 * @param  array<string, mixed>  $filters
 */
function cell(Tenant $tenant, array $row, string $heading, array $filters = []): string|int|null
{
    $index = array_search($heading, exportHeadings($tenant, $filters), strict: true);

    expect($index)->not->toBeFalse("No column named {$heading}");

    return $row[$index];
}

it('writes a heading for every value in a row, and no more', function () {
    $tenant = Tenant::factory()->create();
    TenantContext::run($tenant->id, fn () => Call::factory()->create());

    $headings = exportHeadings($tenant);
    $rows = exportRows($tenant);

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toHaveCount(count($headings));
});

// CE-4's honesty trap. `leads.attempts` exists as a column and nothing in the call path
// ever increments it, so exporting it would produce authoritative-looking fiction. This
// asserts on the heading list rather than a value, so nobody re-adds it by helpfulness.
it('never exports a call-attempt count, however the lead is set up', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function () {
        $campaign = Campaign::factory()->create();
        $lead = Lead::factory()->create(['campaign_id' => $campaign->id, 'attempts' => 47]);
        Call::factory()->forLead($lead)->create();
    });

    $flat = implode(' ', array_map('strtolower', exportHeadings($tenant)));

    expect($flat)->not->toContain('attempt')->not->toContain('call count');
    // Cell-by-cell, not a substring sweep: 47 turns up inside a random phone number
    // often enough that a text search proves nothing.
    expect(exportRows($tenant)[0])->not->toContain('47')->not->toContain(47);
});

it('fills the menu from the real call, not from blanks', function () {
    $tenant = Tenant::factory()->create();

    $ticket = (string) Str::uuid();

    $call = TenantContext::run($tenant->id, function () use ($ticket): Call {
        $agent = User::factory()->create(['name' => 'Asha']);
        $campaign = Campaign::factory()->create(['name' => 'Renewals']);
        $lead = Lead::factory()->create(['campaign_id' => $campaign->id, 'name' => 'Ravi Kumar', 'email' => 'ravi@example.test', 'city' => 'Nagpur']);
        $sale = Disposition::factory()->sale()->create(['label' => 'Sold']);

        return Call::factory()->forAgent($agent)->forLead($lead)->create([
            'direction' => CallDirection::Inbound,
            'disposition_id' => $sale->id,
            'outcome' => CallOutcome::Answered,
            'from_number' => '9812345678',
            'to_number' => '1800111222',
            'correlation_id' => $ticket,
            'started_at' => now()->subSeconds(60),
            'ringing_at' => now()->subSeconds(45),
            'answered_at' => now()->subSeconds(30),
            'ended_at' => now()->subSeconds(10),
        ]);
    });

    $row = exportRows($tenant)[0];

    expect(cell($tenant, $row, 'Call ID'))->toBe($call->id)
        ->and(cell($tenant, $row, 'Ticket'))->toBe($ticket)
        ->and(cell($tenant, $row, 'Direction'))->toBe('Inbound')
        ->and(cell($tenant, $row, 'Agent'))->toBe('Asha')
        ->and(cell($tenant, $row, 'Campaign'))->toBe('Renewals')
        ->and(cell($tenant, $row, 'Lead name'))->toBe('Ravi Kumar')
        // CP-7: the details CP-3's live-call form captures. A5 is the reason that
        // form exists — the client asks for these next to the call report — so a
        // customer written and never exported would be the write-only chore CPQ-5
        // was asked to rule out.
        ->and(cell($tenant, $row, 'Lead email'))->toBe('ravi@example.test')
        ->and(cell($tenant, $row, 'Lead city'))->toBe('Nagpur')
        ->and(cell($tenant, $row, 'Disposition'))->toBe('Sold')
        ->and(cell($tenant, $row, 'Sale'))->toBe('Yes')
        ->and(cell($tenant, $row, 'Outcome (provisional)'))->toBe('Answered')
        // Inbound: they dialled us, so the customer is the from_number and our own
        // number is the one they rang.
        ->and(cell($tenant, $row, 'Customer number'))->toBe('9812345678')
        ->and(cell($tenant, $row, 'Our number'))->toBe('1800111222')
        // The wait, split their way: 15s holding, 15s ringing, 30s all told.
        ->and(cell($tenant, $row, 'Queue time'))->toBe('15')
        ->and(cell($tenant, $row, 'Ring time'))->toBe('15')
        ->and(cell($tenant, $row, 'Waited'))->toBe('30')
        ->and(cell($tenant, $row, 'Talked'))->toBe('20')
        // Inbound has no dial time — that column is their outbound "Answered time".
        ->and(cell($tenant, $row, 'Dial time'))->toBe('');
});

// CP-5: the disposition says which box the call went in; the note says what was
// actually said. A client querying one row asks about the second.
it('exports the agent note beside the coded outcome', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, fn (): Call => Call::factory()->create([
        'notes' => 'Wants the quote emailed by Friday.',
    ]));

    $row = exportRows($tenant)[0];

    expect(cell($tenant, $row, 'Call notes'))->toBe('Wants the quote emailed by Friday.');
});

it('reads the customer and our own number from opposite ends on outbound', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, fn () => Call::factory()->create([
        'direction' => CallDirection::Outbound,
        'from_number' => '1800111222',
        'to_number' => '9812345678',
        'ringing_at' => now()->subSeconds(20),
        'answered_at' => now()->subSeconds(8),
    ]));

    $row = exportRows($tenant)[0];

    expect(cell($tenant, $row, 'Customer number'))->toBe('9812345678')
        ->and(cell($tenant, $row, 'Our number'))->toBe('1800111222')
        ->and(cell($tenant, $row, 'Dial time'))->toBe('12');
});

// hold.md H-3 + H-7, on the file the client actually reads: Held is its own column and
// Talked no longer counts it. The BPO team lead asked for the held time as its own
// figure, not only rolled into the handle time.
it('writes the held time as its own column and reads it out of Talked', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, fn () => Call::factory()->create([
        'answered_at' => now()->subSeconds(300),
        'ended_at' => now(),
        'hold_seconds' => 180,
    ]));

    $row = exportRows($tenant)[0];

    expect(cell($tenant, $row, 'Held'))->toBe('180')
        // Five minutes on the line, three of them music.
        ->and(cell($tenant, $row, 'Talked'))->toBe('120');
});

// H-7's own distinction, and the same one S109 found wrong in the Wrap-up column: zero
// is a measurement, blank is the absence of one. A call recorded before Hold shipped has
// no hold information at all and must not read as a confident nought.
it('leaves the held column blank on a call recorded before Hold shipped', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, fn () => Call::factory()->create([
        'answered_at' => now()->subSeconds(300),
        'ended_at' => now(),
        'hold_seconds' => null,
    ]));

    $row = exportRows($tenant)[0];

    expect(cell($tenant, $row, 'Held'))->toBe('')
        // …and its Talked figure is untouched, which is what makes shipping this safe.
        ->and(cell($tenant, $row, 'Talked'))->toBe('300');
});

it('leaves a missed call blank where nothing happened and filled where it did', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, fn () => Call::factory()->inbound()->create([
        'agent_id' => null,
        'disposition_id' => null,
        'outcome' => CallOutcome::Abandoned,
        'started_at' => now()->subSeconds(40),
        'answered_at' => null,
        'ended_at' => now()->subSeconds(10),
    ]));

    $row = exportRows($tenant)[0];

    expect(cell($tenant, $row, 'Agent'))->toBe('')
        ->and(cell($tenant, $row, 'Talked'))->toBe('')
        ->and(cell($tenant, $row, 'Disposition'))->toBe('')
        ->and(cell($tenant, $row, 'Sale'))->toBe('')
        // Nobody picked up, so the wait ended when we stopped waiting (CT-7).
        ->and(cell($tenant, $row, 'Waited'))->toBe('30')
        // BLANK, not zero. The listener writes this row at the moment it gives up, so
        // the call's end and the row's own creation are the same instant and the span
        // is a truthful-looking `0` — which reads as "the agent wrapped up instantly"
        // on a call that had no agent at all.
        ->and(cell($tenant, $row, 'Wrap-up'))->toBe('');
});

// The other side of the same rule: a call somebody DID answer still reports the time
// the agent spent typing after it ended.
it('reports wrap-up time on a call an agent actually handled', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function (): void {
        $agent = User::factory()->create();

        Call::factory()->forAgent($agent)->inbound()->create([
            'answered_at' => now()->subSeconds(90),
            'ended_at' => now()->subSeconds(40),
            'created_at' => now()->subSeconds(10),
        ]);
    });

    expect(cell($tenant, exportRows($tenant)[0], 'Wrap-up'))->toBe('30');
});

// CT-5: a transferred call is two rows sharing one ticket, and both say so.
it('flags both halves of a transferred call as passed on', function () {
    $tenant = Tenant::factory()->create();

    $shared = (string) Str::uuid();
    $lonely = (string) Str::uuid();

    TenantContext::run($tenant->id, function () use ($shared, $lonely) {
        Call::factory()->count(2)->create(['correlation_id' => $shared]);
        Call::factory()->create(['correlation_id' => $lonely]);
    });

    $byTicket = collect(exportRows($tenant))->groupBy(fn (array $r): string => (string) cell($tenant, $r, 'Ticket'));

    expect($byTicket[$shared]->every(fn (array $r): bool => cell($tenant, $r, 'Passed on') === 'Yes'))->toBeTrue()
        ->and(cell($tenant, $byTicket[$lonely][0], 'Passed on'))->toBe('No');
});

it('does not treat two calls with no ticket at all as passed on', function () {
    $tenant = Tenant::factory()->create();
    TenantContext::run($tenant->id, fn () => Call::factory()->count(2)->create(['correlation_id' => null]));

    expect(collect(exportRows($tenant))->every(fn (array $r): bool => cell($tenant, $r, 'Passed on') === 'No'))
        ->toBeTrue();
});

it('exports durations as whole seconds by default and as a clock with the toggle', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, fn () => Call::factory()->create([
        'answered_at' => now()->subSeconds(125),
        'ended_at' => now(),
    ]));

    expect(cell($tenant, exportRows($tenant)[0], 'Talked'))->toBe('125')
        ->and(cell($tenant, exportRows($tenant, ['durationFormat' => 'clock'])[0], 'Talked'))->toBe('02:05');
});

it('links a recording only when there is one', function () {
    $tenant = Tenant::factory()->create();

    $with = TenantContext::run($tenant->id, fn (): Call => Call::factory()->withRecording()->create());
    TenantContext::run($tenant->id, fn () => Call::factory()->create());

    $rows = collect(exportRows($tenant))->keyBy(fn (array $r): int => (int) cell($tenant, $r, 'Call ID'));

    expect(cell($tenant, $rows[$with->id], 'Recording'))->toBe('Yes')
        ->and(cell($tenant, $rows[$with->id], 'Recording link'))->toBe(route('calls.recording', $with))
        ->and(collect($rows)->firstWhere(fn (array $r): bool => cell($tenant, $r, 'Recording') === 'No'))
        ->not->toBeNull();
});

// ---------------------------------------------------------------- the date boundary

// CE-4a. The half-open end is the whole reason this test exists: `<= endDate` reads as
// midnight and would drop nearly the entire final day. Run for a UTC client so the two
// boundaries under test are CE-4a's and not CE-10a's — the client-zone cut has its own
// tests below.
it('includes a call late on the last day and excludes one early the next', function () {
    $tenant = Tenant::factory()->create(['timezone' => 'UTC']);

    [$inside, $outside] = TenantContext::run($tenant->id, fn (): array => [
        Call::factory()->create(['created_at' => '2026-08-13 23:50:00']),
        Call::factory()->create(['created_at' => '2026-08-14 00:05:00']),
    ]);

    $ids = collect(exportRows($tenant, ['startDate' => '2026-08-01', 'endDate' => '2026-08-13']))
        ->map(fn (array $r): int => (int) cell($tenant, $r, 'Call ID'))
        ->all();

    expect($ids)->toContain($inside->id)->not->toContain($outside->id);
});

it('includes a call at the very start of the first day', function () {
    $tenant = Tenant::factory()->create();

    $call = TenantContext::run($tenant->id, fn (): Call => Call::factory()
        ->create(['created_at' => '2026-08-01 00:00:00']));

    $ids = collect(exportRows($tenant, ['startDate' => '2026-08-01', 'endDate' => '2026-08-01']))
        ->map(fn (array $r): int => (int) cell($tenant, $r, 'Call ID'))
        ->all();

    expect($ids)->toBe([$call->id]);
});

// CE-4a's first reason: filtering on started_at would have deleted every outbound call,
// because beginOutboundCall writes no arrival at all (CT-8, nobody waited).
it('keeps outbound calls in a date range even though they have no start moment', function () {
    $tenant = Tenant::factory()->create();

    $call = TenantContext::run($tenant->id, fn (): Call => Call::factory()->create([
        'direction' => CallDirection::Outbound,
        'started_at' => null,
        'created_at' => '2026-08-10 12:00:00',
    ]));

    $ids = collect(exportRows($tenant, ['startDate' => '2026-08-01', 'endDate' => '2026-08-31']))
        ->map(fn (array $r): int => (int) cell($tenant, $r, 'Call ID'))
        ->all();

    expect($ids)->toBe([$call->id]);
});

// §8's parity test: the export and the Calls list must agree on WHICH calls fall in a
// range. Run for a UTC client so both sides mean the same instants — CE-10a deliberately
// moves the export's boundary into the client's zone, and that divergence is its own
// test below. **This test proves the two agree on which calls, not on which zone.**
it('returns the same calls as the Calls list for the same date range', function () {
    $tenant = Tenant::factory()->create(['timezone' => 'UTC']);

    TenantContext::run($tenant->id, function () {
        Call::factory()->create(['created_at' => '2026-08-12 09:00:00']);
        Call::factory()->create(['created_at' => '2026-08-13 23:59:00']);
        Call::factory()->create(['created_at' => '2026-08-14 00:01:00']);
    });

    $exported = collect(exportRows($tenant, ['startDate' => '2026-08-12', 'endDate' => '2026-08-13']))
        ->map(fn (array $r): int => (int) cell($tenant, $r, 'Call ID'))
        ->all();

    // The Calls list's own filter, verbatim from CallsTable.
    $listed = TenantContext::run($tenant->id, fn (): array => Call::query()
        ->whereDate('created_at', '>=', '2026-08-12')
        ->whereDate('created_at', '<=', '2026-08-13')
        ->orderByDesc('created_at')
        ->pluck('id')
        ->all());

    expect($exported)->toBe($listed);
});

// ------------------------------------------------------------------ the client's zone

// CE-10. The heading names the zone because a bare timestamp in a file that leaves the
// building will be read as local time by somebody, eventually.
it('writes timestamps in the client\'s own zone and names it in the heading', function () {
    $tenant = Tenant::factory()->create(['timezone' => 'Asia/Kolkata']);

    TenantContext::run($tenant->id, fn (): Call => Call::factory()->create([
        'created_at' => '2026-08-13 09:00:00',
        'started_at' => '2026-08-13 09:00:00',
    ]));

    $row = exportRows($tenant)[0];

    // 09:00 UTC is 14:30 on an India-time floor.
    expect(exportHeadings($tenant))->toContain('Started (IST)')
        ->and(cell($tenant, $row, 'Started (IST)'))->toBe('2026-08-13 14:30:00');
});

it('falls back to the system default zone when the client has set none', function () {
    config(['app.report_timezone' => 'Asia/Dubai']);

    $tenant = Tenant::factory()->create(['timezone' => null]);

    TenantContext::run($tenant->id, fn (): Call => Call::factory()->create([
        'created_at' => '2026-08-13 09:00:00',
        'started_at' => '2026-08-13 09:00:00',
    ]));

    // Dubai has no abbreviation of its own, so PHP names the offset — still a named
    // thing rather than a bare timestamp, which is the whole point of the heading.
    expect(cell($tenant, exportRows($tenant)[0], 'Started (+04)'))->toBe('2026-08-13 13:00:00');
});

// CE-10a — the half of CE-10 that is easy to miss. Printing India time while cutting the
// day at UTC midnight produces a file that contradicts its own heading: the first four
// and a half hours of the shift are missing and the last four and a half belong to the
// next day.
it('cuts the day in the client\'s zone, not ours', function () {
    $tenant = Tenant::factory()->create(['timezone' => 'Asia/Kolkata']);

    [$before, $first, $last, $after] = TenantContext::run($tenant->id, fn (): array => [
        // 18:29 UTC on the 12th is 23:59 on the 12th in India — the day before.
        Call::factory()->create(['created_at' => '2026-08-12 18:29:00']),
        // 18:30 UTC on the 12th is midnight on the 13th in India — the first moment.
        Call::factory()->create(['created_at' => '2026-08-12 18:30:00']),
        // 18:29 UTC on the 13th is 23:59 on the 13th — the last moment.
        Call::factory()->create(['created_at' => '2026-08-13 18:29:00']),
        // 18:30 UTC on the 13th is already the 14th in India.
        Call::factory()->create(['created_at' => '2026-08-13 18:30:00']),
    ]);

    $ids = collect(exportRows($tenant, ['startDate' => '2026-08-13', 'endDate' => '2026-08-13']))
        ->map(fn (array $r): int => (int) cell($tenant, $r, 'Call ID'))
        ->all();

    expect($ids)->toEqualCanonicalizing([$first->id, $last->id])
        ->not->toContain($before->id)
        ->not->toContain($after->id);
});

// ---------------------------------------------------------------- the other filters

it('narrows by each filter it offers', function (string $key, callable $make, int $expected) {
    $tenant = Tenant::factory()->create();
    $value = TenantContext::run($tenant->id, $make);

    expect(exportRows($tenant, [$key => $value]))->toHaveCount($expected);
})->with([
    'agent' => ['agentId', function (): int {
        $agent = User::factory()->create();
        Call::factory()->forAgent($agent)->create();
        Call::factory()->create();

        return $agent->id;
    }, 1],
    'campaign' => ['campaignId', function (): int {
        $campaign = Campaign::factory()->create();
        Call::factory()->create(['campaign_id' => $campaign->id]);
        Call::factory()->create();

        return $campaign->id;
    }, 1],
    'disposition' => ['dispositionId', function (): int {
        $disposition = Disposition::factory()->create();
        Call::factory()->count(2)->create(['disposition_id' => $disposition->id]);
        Call::factory()->create();

        return $disposition->id;
    }, 2],
    'direction' => ['direction', function (): string {
        Call::factory()->inbound()->create();
        Call::factory()->create();

        return CallDirection::Inbound->value;
    }, 1],
    'outcome' => ['outcome', function (): string {
        Call::factory()->noAnswer()->create();
        Call::factory()->create();

        return CallOutcome::NoAnswer->value;
    }, 1],
    'has a recording' => ['hasRecording', function (): string {
        Call::factory()->withRecording()->create();
        Call::factory()->count(2)->create();

        return '1';
    }, 1],
    'has no recording' => ['hasRecording', function (): string {
        Call::factory()->withRecording()->create();
        Call::factory()->count(2)->create();

        return '0';
    }, 2],
]);

it('applies every filter together rather than only the last one', function () {
    $tenant = Tenant::factory()->create();

    $agent = TenantContext::run($tenant->id, function (): User {
        $agent = User::factory()->create();
        // Matches everything.
        Call::factory()->forAgent($agent)->inbound()->create(['created_at' => '2026-08-10 10:00:00']);
        // Right agent, wrong direction.
        Call::factory()->forAgent($agent)->create(['created_at' => '2026-08-10 10:00:00']);
        // Right direction, wrong date.
        Call::factory()->forAgent($agent)->inbound()->create(['created_at' => '2026-09-10 10:00:00']);

        return $agent;
    });

    expect(exportRows($tenant, [
        'agentId' => $agent->id,
        'direction' => CallDirection::Inbound->value,
        'startDate' => '2026-08-01',
        'endDate' => '2026-08-31',
    ]))->toHaveCount(1);
});

// ---------------------------------------------------------------- cost

// CE-5a's whole point. Under cursor() — or with a relation missing from the pre-load —
// this becomes one query per row, and the export gets slower than the array version it
// replaced. A fixed row count against a fixed query budget is what proves it.
it('issues a bounded number of queries however many rows there are', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function () {
        $agent = User::factory()->create();
        $campaign = Campaign::factory()->create();
        $lead = Lead::factory()->create(['campaign_id' => $campaign->id]);
        $disposition = Disposition::factory()->create();

        Call::factory()->count(60)->forAgent($agent)->forLead($lead)->create([
            'disposition_id' => $disposition->id,
        ]);
    });

    DB::enableQueryLog();
    DB::flushQueryLog();

    exportRows($tenant);

    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // One chunk fetch + one per pre-loaded relation, plus the context SETs around the
    // run. Nowhere near sixty; a per-row read would put this past two hundred.
    expect($queries)->toBeLessThan(20);
});

// ---------------------------------------------------------------- the Client column

it('adds a populated Client column for global staff and hides it for everyone else', function () {
    $a = Tenant::factory()->create(['name' => 'Acme']);
    $b = Tenant::factory()->create(['name' => 'Beta']);
    $hc = reportsHcUser(RoleName::OpsManager->value);

    TenantContext::run($a->id, fn () => Call::factory()->create());
    TenantContext::run($b->id, fn () => Call::factory()->create());

    // A client's own supervisor: every row is the same client, so the column is noise.
    expect(exportHeadings($a))->not->toContain('Client');

    // Global staff in the all-clients posture.
    $this->actingAs($hc);
    TenantContext::applyWebRequest(null, crossTenant: true);

    $headings = (new CallExportRows([]))->headings();
    $rows = iterator_to_array((new CallExportRows([]))->rows(), preserve_keys: false);
    $clientIndex = array_search('Client', $headings, strict: true);

    expect($clientIndex)->not->toBeFalse()
        ->and(collect($rows)->pluck($clientIndex)->sort()->values()->all())->toBe(['Acme', 'Beta']);
});

// ------------------------------------------------------------------- CE-12 custom fields

// CE-12. About a third of their sheet's columns are a CUSTOMER record, not a call
// record, and every client's customer looks different — so the campaign's own defined
// fields become the columns rather than fifteen of somebody else's.
it('appends the selected campaign\'s own customer fields as extra columns', function () {
    $tenant = Tenant::factory()->create();

    [$campaign, $call] = TenantContext::run($tenant->id, function (): array {
        $campaign = Campaign::factory()->create([
            'custom_fields' => [
                ['key' => 'policy_number', 'label' => 'Policy Number', 'type' => 'text'],
                ['key' => 'postcode', 'label' => 'Postcode', 'type' => 'text'],
            ],
        ]);
        $lead = Lead::factory()->create([
            'campaign_id' => $campaign->id,
            'custom_fields' => ['policy_number' => 'PN-9931', 'postcode' => 'HP1 2AB'],
        ]);

        return [$campaign, Call::factory()->create(['campaign_id' => $campaign->id, 'lead_id' => $lead->id])];
    });

    $filters = ['campaignId' => $campaign->id];
    $row = exportRows($tenant, $filters)[0];

    expect(exportHeadings($tenant, $filters))->toContain('Policy Number')->toContain('Postcode')
        ->and(cell($tenant, $row, 'Policy Number', $filters))->toBe('PN-9931')
        ->and(cell($tenant, $row, 'Postcode', $filters))->toBe('HP1 2AB');
});

// CE-12a — the restriction, and the reason for it: a CSV writes its headings once,
// before any row, so an export spanning two campaigns cannot know its own shape in time.
it('writes no custom columns at all when no campaign is selected', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function (): void {
        $campaign = Campaign::factory()->create([
            'custom_fields' => [['key' => 'policy_number', 'label' => 'Policy Number', 'type' => 'text']],
        ]);
        $lead = Lead::factory()->create([
            'campaign_id' => $campaign->id,
            'custom_fields' => ['policy_number' => 'PN-9931'],
        ]);
        Call::factory()->create(['campaign_id' => $campaign->id, 'lead_id' => $lead->id]);
    });

    expect(exportHeadings($tenant))->not->toContain('Policy Number')
        ->and(exportRows($tenant)[0])->toHaveCount(count(exportHeadings($tenant)));
});

// A call with no lead has no customer record, so those columns are blank — and, more
// importantly, still PRESENT, or the row would be shorter than its own heading.
it('keeps the row and the heading the same width when the call has no lead', function () {
    $tenant = Tenant::factory()->create();

    $campaign = TenantContext::run($tenant->id, function (): Campaign {
        $campaign = Campaign::factory()->create([
            'custom_fields' => [['key' => 'policy_number', 'label' => 'Policy Number', 'type' => 'text']],
        ]);
        Call::factory()->create(['campaign_id' => $campaign->id, 'lead_id' => null]);

        return $campaign;
    });

    $filters = ['campaignId' => $campaign->id];

    expect(exportRows($tenant, $filters)[0])->toHaveCount(count(exportHeadings($tenant, $filters)))
        ->and(cell($tenant, exportRows($tenant, $filters)[0], 'Policy Number', $filters))->toBe('');
});
