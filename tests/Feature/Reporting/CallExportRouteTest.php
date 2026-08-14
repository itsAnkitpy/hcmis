<?php

use App\Enums\CallOutcome;
use App\Enums\RoleName;
use App\Filament\Pages\Reports\CallExportReport;
use App\Models\Call;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::resetWebRequest();
    TenantContext::forget();
});

/**
 * Call Export step 1 (call-export.md CE-5b) — the route and its four guards. The
 * columns are deliberately trivial here; CE-4's real menu and CE-5a's filtered query
 * are the next build step, and these tests are about the guards, not the contents.
 */

/** Read a streamed download's body — the ONLY way the generator (and its lock release) runs. */
function exportBody(TestResponse $response): string
{
    return $response->streamedContent();
}

/**
 * The refusal the supervisor actually reads. Both refusals redirect to the export screen
 * and leave a Filament notification in the session, which the panel renders on arrival —
 * asserting the redirect alone would pass against an empty message (S108: `abort()` with
 * a message renders as a bare "An Error Occurred" once APP_DEBUG is off).
 */
function refusalMessage(): string
{
    return collect(session('filament.notifications', []))
        ->map(fn (array $notification): string => ($notification['title'] ?? '').' '.($notification['body'] ?? ''))
        ->implode(' ');
}

/**
 * The call ids in the file, in the order written. Parsed from the first column rather
 * than searched for as text: a bare `expect($body)->toContain('5')` matches the 5 in a
 * timestamp and passes or fails by luck of the ids.
 *
 * @return array<int, int>
 */
function exportedIds(TestResponse $response): array
{
    return collect(explode("\n", trim(exportBody($response))))
        ->skip(1)
        ->filter()
        ->map(fn (string $line): int => (int) str_getcsv($line, escape: '')[0])
        ->values()
        ->all();
}

/**
 * One exported row as heading => cell, keyed off the file's OWN heading line — a column
 * moving cannot then make a test quietly assert about its neighbour. Cells arrive
 * exactly as written, CE-7's guard tab included.
 *
 * @return array<string, string>
 */
function exportedRow(TestResponse $response, int $index = 0): array
{
    $lines = collect(explode("\n", trim(exportBody($response))))->filter()->values();

    return array_combine(
        str_getcsv(ltrim($lines->first(), "\xEF\xBB\xBF"), escape: ''),
        str_getcsv($lines->get($index + 1), escape: ''),
    );
}

it('streams a CSV to a permitted reader of the owning client', function () {
    // Pinned to UTC so this test is about the file's opening bytes, not about which
    // zone the heading names — CE-10's own tests cover that.
    $tenant = Tenant::factory()->create(['timezone' => 'UTC']);
    $tl = clientUserWithRole($tenant, RoleName::TeamLeader->value);
    TenantContext::run($tenant->id, fn () => Call::factory()->count(3)->create());

    $response = $this->actingAs($tl)->get(route('calls.export'))->assertOk();

    // CE-7 point 2: the three bytes that tell Excel on Windows this is UTF-8. Asserted
    // here rather than in its own test because it is the first thing in the file, and
    // its absence is invisible until an accented name reaches a client's screen.
    expect(exportBody($response))->toStartWith("\xEF\xBB\xBF".'"Call ID",Ticket,"Started (UTC)",Direction');
});

// CE-5c — the guard that could not be copied from the recording route. An agent passes
// `view` on their OWN call and can reach calls.recording today; the export has no row to
// scope against, so `view` here would open every call to every agent. This test names an
// agent specifically for that reason — "a user without permission" would pass either way.
it('refuses an agent, who passes the per-row view ability but not viewAny', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $this->actingAs($agent)->get(route('calls.export'))->assertForbidden();
});

it('refuses an unauthenticated request', function () {
    $this->get(route('calls.export'))->assertRedirect(route('filament.admin.auth.login'));
});

// CE-2's whole argument: the tenant wall applies for the entire streamed write.
it('never leaks another client\'s calls into the file', function () {
    $mine = Tenant::factory()->create();
    $theirs = Tenant::factory()->create();
    $tl = clientUserWithRole($mine, RoleName::TeamLeader->value);

    $ours = TenantContext::run($mine->id, fn (): Call => Call::factory()->create());
    $foreign = TenantContext::run($theirs->id, fn (): Call => Call::factory()->create());

    $ids = exportedIds($this->actingAs($tl)->get(route('calls.export'))->assertOk());

    expect($ids)->toContain($ours->id)
        ->and($ids)->not->toContain($foreign->id);
});

// CE-5d — a web address is a trust boundary a Filament form is not.
it('rejects rubbish query parameters instead of 500ing', function (array $query) {
    $tenant = Tenant::factory()->create();
    $tl = clientUserWithRole($tenant, RoleName::TeamLeader->value);

    $this->actingAs($tl)
        ->get(route('calls.export', $query))
        ->assertSessionHasErrors(array_key_first($query));
})->with([
    'a non-date in the range' => [['startDate' => 'nonsense']],
    'an array where one value belongs' => [['agentId' => ['1', '2']]],
    'a direction outside the enum' => [['direction' => 'sideways']],
    'an outcome outside the enum' => [['outcome' => 'maybe']],
]);

it('accepts an empty filter set as unfiltered', function () {
    $tenant = Tenant::factory()->create();
    $tl = clientUserWithRole($tenant, RoleName::TeamLeader->value);

    $this->actingAs($tl)->get(route('calls.export'))->assertOk()->assertSessionHasNoErrors();
});

// CE-3a — one export at a time per user. The lock is taken in the controller and
// released inside the generator, so it is held for exactly as long as the file is
// being written. Holding it by hand here stands in for a first export still running.
it('refuses a second export while the user\'s first is still writing', function () {
    $tenant = Tenant::factory()->create();
    $tl = clientUserWithRole($tenant, RoleName::TeamLeader->value);

    Cache::lock("call-export:{$tl->id}", 900)->get();

    $this->actingAs($tl)
        ->get(route('calls.export'))
        ->assertRedirect(CallExportReport::getUrl());

    expect(refusalMessage())->toContain('still downloading');
});

// CE-7 points 1 and 4. The guard is a leading tab, which does not print and makes the
// cell text — so the number both survives the round trip and is never evaluated as a
// formula or rounded into scientific notation.
it('guards numbers a spreadsheet would otherwise mangle', function (string $stored) {
    $tenant = Tenant::factory()->create();
    $tl = clientUserWithRole($tenant, RoleName::TeamLeader->value);

    TenantContext::run($tenant->id, fn () => Call::factory()->inbound()->create(['from_number' => $stored]));

    $cell = exportedRow($this->actingAs($tl)->get(route('calls.export'))->assertOk())['Customer number'];

    expect($cell)->toStartWith("\t")->and(ltrim($cell, "\t"))->toBe($stored);
})->with([
    'a number beginning + is not run as a formula' => ['+919876543210'],
    'a leading zero is not eaten' => ['01772345678'],
    'a long run of digits is not rounded into scientific notation' => ['919876543210'],
]);

// The other half of the same rule: the guard is applied by shape, so it must NOT reach
// the columns a supervisor totals in a spreadsheet. A tab here turns Talked into text
// and =SUM() silently returns zero.
it('leaves durations and ids as plain numbers a spreadsheet can add up', function () {
    $tenant = Tenant::factory()->create();
    $tl = clientUserWithRole($tenant, RoleName::TeamLeader->value);

    TenantContext::run($tenant->id, fn () => Call::factory()->inbound()->create([
        'answered_at' => '2026-08-13 09:00:00',
        'ended_at' => '2026-08-13 09:02:05',
    ]));

    $row = exportedRow($this->actingAs($tl)->get(route('calls.export'))->assertOk());

    expect($row['Talked'])->toBe('125')->and($row['Call ID'])->not->toStartWith("\t");
});

// CE-3 — the row cap. It refuses rather than truncating, and the message carries the
// real count and the real limit, because "too many rows" is a dead end. The cap is
// lowered here rather than seeding 50,001 calls; the code path is identical.
it('refuses a result over the row cap, naming the real count and the limit', function () {
    config(['hcims.call_export_max_rows' => 2]);

    $tenant = Tenant::factory()->create();
    $tl = clientUserWithRole($tenant, RoleName::TeamLeader->value);
    TenantContext::run($tenant->id, fn () => Call::factory()->count(3)->create());

    $this->actingAs($tl)
        ->get(route('calls.export'))
        ->assertRedirect(CallExportReport::getUrl());

    expect(refusalMessage())->toContain('3 calls')->toContain('limit is 2');
});

// The refusal must not be a lockout: the supervisor is told to narrow the range and try
// again, so the per-user lock cannot still be held when they do.
it('leaves the export lock free after refusing an oversized result', function () {
    config(['hcims.call_export_max_rows' => 1]);

    $tenant = Tenant::factory()->create();
    $tl = clientUserWithRole($tenant, RoleName::TeamLeader->value);
    TenantContext::run($tenant->id, fn () => Call::factory()->count(2)->create());

    $this->actingAs($tl)->get(route('calls.export'));

    expect(Cache::lock("call-export:{$tl->id}", 900)->get())->toBeTrue();
});

// CE-3 deleted the 7-day cap in S105: a range is refused for being BIG, never for being
// WIDE. This test exists so nobody reinstates the day limit as a "sensible default".
it('accepts a range far wider than seven days when it returns few enough rows', function () {
    $tenant = Tenant::factory()->create();
    $tl = clientUserWithRole($tenant, RoleName::TeamLeader->value);

    $calls = TenantContext::run($tenant->id, fn () => collect([
        Call::factory()->create(['created_at' => '2026-06-02 09:00:00']),
        Call::factory()->create(['created_at' => '2026-07-15 09:00:00']),
        Call::factory()->create(['created_at' => '2026-08-13 09:00:00']),
    ]));

    $ids = exportedIds($this->actingAs($tl)->get(route('calls.export', [
        'startDate' => '2026-06-01',
        'endDate' => '2026-08-13',
    ]))->assertOk());

    expect($ids)->toHaveCount(3)->toEqualCanonicalizing($calls->pluck('id')->all());
});

it('does not refuse a different user\'s export', function () {
    $tenant = Tenant::factory()->create();
    $busy = clientUserWithRole($tenant, RoleName::TeamLeader->value);
    $other = clientUserWithRole($tenant, RoleName::TeamLeader->value);

    Cache::lock("call-export:{$busy->id}", 900)->get();

    $this->actingAs($other)->get(route('calls.export'))->assertOk();
});

// The finding that made this test worth writing (S107): streamDownload hands its
// callback back UNRUN, so a `finally` in the controller body would release the lock
// before the first row was written — and every test above would still pass. This is
// the one that fails if the release drifts back out of the generator.
it('releases the lock only after the file has been written', function () {
    $tenant = Tenant::factory()->create();
    $tl = clientUserWithRole($tenant, RoleName::TeamLeader->value);
    TenantContext::run($tenant->id, fn () => Call::factory()->count(2)->create());

    $response = $this->actingAs($tl)->get(route('calls.export'))->assertOk();

    // Response built, body not yet read: the writer has not run, so the lock is held.
    expect(Cache::lock("call-export:{$tl->id}", 900)->get())->toBeFalse();

    exportBody($response);

    expect(Cache::lock("call-export:{$tl->id}", 900)->get())->toBeTrue();
});

it('releases the lock when the download is abandoned part-way', function () {
    $tenant = Tenant::factory()->create();
    $tl = clientUserWithRole($tenant, RoleName::TeamLeader->value);
    TenantContext::run($tenant->id, fn () => Call::factory()->count(5)->create());

    $response = $this->actingAs($tl)->get(route('calls.export'))->assertOk();
    exportBody($response);
    unset($response);

    expect(Cache::lock("call-export:{$tl->id}", 900)->get())->toBeTrue();
});

it('lets global staff export across clients', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $hc = reportsHcUser(RoleName::OpsManager->value);

    $first = TenantContext::run($a->id, fn (): Call => Call::factory()->create());
    $second = TenantContext::run($b->id, fn (): Call => Call::factory()->create());

    $ids = exportedIds($this->actingAs($hc)->get(route('calls.export'))->assertOk());

    expect($ids)->toContain($first->id)->toContain($second->id);
});

// CE-5a's order finding (S107): lazyById() walks ids upwards, the Calls list shows
// newest first. This asserts the direction, which a set comparison would not.
it('writes newest first, matching the Calls list order', function () {
    $tenant = Tenant::factory()->create();
    $tl = clientUserWithRole($tenant, RoleName::TeamLeader->value);
    $calls = TenantContext::run($tenant->id, fn () => Call::factory()->count(3)->create());

    $ids = exportedIds($this->actingAs($tl)->get(route('calls.export'))->assertOk());

    expect($ids)->toBe($calls->pluck('id')->sortDesc()->values()->all());
});

it('is reachable from the export screen with the filters attached', function () {
    $tenant = Tenant::factory()->create();
    $tl = clientUserWithRole($tenant, RoleName::TeamLeader->value);
    $agent = TenantContext::run($tenant->id, fn (): User => User::factory()->create());

    $page = new CallExportReport;
    $page->filters = ['startDate' => '2026-08-01', 'endDate' => null, 'agentId' => $agent->id];

    $this->actingAs($tl);

    expect($page->downloadUrl())
        ->toContain('startDate=2026-08-01')
        ->toContain('agentId='.$agent->id)
        ->not->toContain('endDate');
});

// Staging (S107) showed three calls on the Calls screen that were absent from the file:
// two abandoned ones with no agent, and one handled by a different agent. This walks the
// whole pipeline over that exact mix — request, guards, query, generator, CSV writer —
// so the export can never silently drop a row shape again.
it('exports every row shape the Calls screen shows, over the full request', function () {
    $tenant = Tenant::factory()->create();
    $tl = clientUserWithRole($tenant, RoleName::TeamLeader->value);

    $expected = TenantContext::run($tenant->id, function (): array {
        $one = User::factory()->create(['name' => 'Abhikesh']);
        $two = User::factory()->create(['name' => 'Demo Agent Two']);

        return [
            Call::factory()->inbound()->forAgent($one)->create(['created_at' => '2026-08-13 04:53:37'])->id,
            // No agent, abandoned, no recording — the missed-call writer's shape.
            Call::factory()->inbound()->create([
                'agent_id' => null, 'outcome' => CallOutcome::Abandoned,
                'created_at' => '2026-08-13 04:53:11', 'started_at' => '2026-08-13 04:48:11',
            ])->id,
            Call::factory()->inbound()->forAgent($one)->create(['created_at' => '2026-08-13 04:48:07'])->id,
            // A different agent, no start moment — a transferred second leg (CT-5).
            Call::factory()->inbound()->forAgent($two)->create([
                'created_at' => '2026-08-12 11:35:00', 'started_at' => null,
            ])->id,
            Call::factory()->inbound()->forAgent($one)->create(['created_at' => '2026-08-12 11:32:29'])->id,
            Call::factory()->inbound()->create([
                'agent_id' => null, 'outcome' => CallOutcome::Abandoned,
                'created_at' => '2026-08-12 05:45:24', 'started_at' => '2026-08-12 05:43:24',
            ])->id,
        ];
    });

    $ids = exportedIds($this->actingAs($tl)->get(route('calls.export', [
        'startDate' => '2026-08-12',
        'endDate' => '2026-08-13',
    ]))->assertOk());

    sort($ids);
    sort($expected);

    expect($ids)->toBe($expected);
});
