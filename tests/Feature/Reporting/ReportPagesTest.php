<?php

use App\Enums\PresenceStatus;
use App\Enums\RoleName;
use App\Enums\StintEndedVia;
use App\Filament\Pages\Reports\AgentProductivityReport;
use App\Filament\Pages\Reports\CallSummaryReport;
use App\Models\AgentStatusHistory;
use App\Models\Call;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::resetWebRequest();
    TenantContext::forget();
});

// reportsHcUser() + clientUserWithRole() + seedCallReportFixture() live in tests/Pest.php
// (shared with the dashboard-widget tests).

// --- RP-4: the report gate mirrors Call Review (reuses CallPolicy verbatim) ---

it('grants report access to global staff', function (string $role) {
    $this->actingAs(reportsHcUser($role));
    TenantContext::applyWebRequest(null, crossTenant: true);

    expect(AgentProductivityReport::canAccess())->toBeTrue()
        ->and(CallSummaryReport::canAccess())->toBeTrue();
})->with([
    RoleName::SuperAdmin->value,
    RoleName::HcAdmin->value,
    RoleName::OpsManager->value,
]);

it('grants report access to per-client auditors (team leader + QC)', function (string $role) {
    $tenant = Tenant::factory()->create();
    $user = clientUserWithRole($tenant, $role);

    TenantContext::run($tenant->id, function () use ($user) {
        $this->actingAs($user->fresh());
        expect(AgentProductivityReport::canAccess())->toBeTrue()
            ->and(CallSummaryReport::canAccess())->toBeTrue();
    });
})->with([
    RoleName::TeamLeader->value,
    RoleName::Qc->value,
]);

it('denies report access to agent, trainer and client user', function (string $role) {
    $tenant = Tenant::factory()->create();
    $user = clientUserWithRole($tenant, $role);

    TenantContext::run($tenant->id, function () use ($user) {
        $this->actingAs($user->fresh());
        expect(AgentProductivityReport::canAccess())->toBeFalse()
            ->and(CallSummaryReport::canAccess())->toBeFalse();
    });
})->with([
    RoleName::Agent->value,
    RoleName::Trainer->value,
    RoleName::ClientUser->value,
]);

// --- The pages load for a permitted user (HasFiltersForm on a custom Page) ---

it('loads the agent productivity report with its per-agent counts', function () {
    $tenant = Tenant::factory()->create();
    seedCallReportFixture($tenant);

    $this->actingAs(reportsHcUser(RoleName::HcAdmin->value));
    TenantContext::applyWebRequest(null, crossTenant: true);

    Livewire::test(AgentProductivityReport::class)
        ->assertOk()
        ->assertSee('Alice')
        ->assertSee('Bob')
        ->assertSee('provisional'); // RP-5 honesty: No-answer is labelled provisional
});

it('loads the report for a per-client team leader, walled to their client', function () {
    $clientA = Tenant::factory()->create();
    $clientB = Tenant::factory()->create();
    seedCallReportFixture($clientA);
    seedCallReportFixture($clientB); // client B's identical fixture must never show

    // A Team Leader of client A. In a real request the top-bar switcher sets the
    // active client; here we set it directly. A multi-client TL works the same way —
    // the report reflects whichever of their clients the switcher has active.
    $teamLeader = clientUserWithRole($clientA, RoleName::TeamLeader->value);

    $this->actingAs($teamLeader);
    TenantContext::applyWebRequest($clientA->id, crossTenant: false);

    $component = Livewire::test(AgentProductivityReport::class)->assertOk();

    // Only client A's five calls — never client B's.
    expect(collect($component->instance()->rows())->sum('total'))->toBe(5);
});

it('loads the call & disposition summary report', function () {
    $tenant = Tenant::factory()->create(['name' => 'Acme']);
    seedCallReportFixture($tenant);
    TenantContext::run($tenant->id, fn () => Call::factory()->create(['was_dialled' => true, 'correlation_id' => (string) Str::uuid()]));

    $this->actingAs(reportsHcUser(RoleName::HcAdmin->value));
    TenantContext::applyWebRequest(null, crossTenant: true);

    Livewire::test(CallSummaryReport::class)
        ->assertOk()
        ->assertSee('Interested') // a disposition label from the breakdown
        ->assertSee('Sold')
        ->assertSee('All campaigns') // the dialer section's day total (DP-12a)
        ->assertSee('Acme');         // and whose day it is, for staff who see every client
});

// --- Filters recompute the table ---

it('recomputes the rows when a filter changes', function () {
    $tenant = Tenant::factory()->create();
    seedCallReportFixture($tenant);

    $this->actingAs(reportsHcUser(RoleName::HcAdmin->value));
    TenantContext::applyWebRequest(null, crossTenant: true);

    $component = Livewire::test(AgentProductivityReport::class);

    // Unfiltered: all 5 calls across the two agents.
    expect(collect($component->instance()->rows())->sum('total'))->toBe(5);

    // Filter to outbound only: 3 calls (Alice 2 + Bob 1).
    $component->set('filters', ['direction' => 'outbound']);
    expect(collect($component->instance()->rows())->sum('total'))->toBe(3);
});

// --- RP-5: the CSV export streams the same numbers as the table ---

it('exports a CSV matching the on-screen agent table', function () {
    $tenant = Tenant::factory()->create();
    seedCallReportFixture($tenant);

    $this->actingAs(reportsHcUser(RoleName::HcAdmin->value));
    TenantContext::applyWebRequest(null, crossTenant: true);

    $response = Livewire::test(AgentProductivityReport::class)->instance()->exportCsv();

    expect($response->headers->get('content-type'))->toContain('text/csv');

    ob_start();
    $response->sendContent();
    $csv = ob_get_clean();

    // The six Shift cells are EMPTY, not 0: this fixture has calls and no stints, so
    // there is no shift record to report (AP-2a/AP-7a). Then the call counts, with
    // Answered second — these fixture calls carry no answered_at, so it reads 0.
    expect($csv)->toContain('Agent,"Logged-in (s)"')            // the header row (quoted where spaced)
        ->and($csv)->toContain('Alice,,,,,,,3,0,1,2,2,1,1,1,66.7')
        ->and($csv)->toContain('Bob,,,,,,,2,0,1,1,1,0,1,0,50');
});

it('wires the export header action to a file download', function () {
    $tenant = Tenant::factory()->create();
    seedCallReportFixture($tenant);

    $this->actingAs(reportsHcUser(RoleName::HcAdmin->value));
    TenantContext::applyWebRequest(null, crossTenant: true);

    Livewire::test(AgentProductivityReport::class)
        ->callAction('export')
        ->assertFileDownloaded('agent-productivity.csv');
});

// ---------------------------------------------------------------------------
// apr.md slice 2 — the shift columns, the row merge and the today fallback
// ---------------------------------------------------------------------------

/** A closed stint on the chosen day, in the report's own tenant context. */
function reportStint(User $user, PresenceStatus $status, Carbon $from, Carbon $to): void
{
    AgentStatusHistory::factory()->forUser($user)->create([
        'status' => $status,
        'started_at' => $from,
        'ended_at' => $to,
        'ended_via' => StintEndedVia::Changed,
    ]);
}

/** A call this agent handled, with known talk / hold / wrap seconds. */
function reportCall(User $agent, int $talk, int $hold, int $wrap): void
{
    $answeredAt = Carbon::today()->setTime(10, 0);

    Call::factory()->forAgent($agent)->create([
        'answered_at' => $answeredAt,
        'ended_at' => $answeredAt->copy()->addSeconds($talk + $hold),
        'hold_seconds' => $hold,
        'created_at' => $answeredAt->copy()->addSeconds($talk + $hold + $wrap),
    ]);
}

/** Run the report as a global reader and return its rows, keyed by agent name. */
function productivityRows(array $filters = []): array
{
    $component = Livewire::test(AgentProductivityReport::class);

    if ($filters !== []) {
        $component->set('filters', $filters);
    }

    return collect($component->instance()->rows())->keyBy('agent')->all();
}

// --- AP-2: the row list is the union, not the grouped call query alone ---

it('gives an agent who logged in and took no call a row with their shift on it', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function (): void {
        $idle = User::factory()->create(['name' => 'Idle Ivy']);
        reportStint($idle, PresenceStatus::Ready, Carbon::today()->setTime(9, 0), Carbon::today()->setTime(17, 0));
    });

    $this->actingAs(reportsHcUser(RoleName::HcAdmin->value));
    TenantContext::applyWebRequest(null, crossTenant: true);

    $rows = productivityRows();

    // Eight hours on the floor, no calls. The grouped call query cannot see her at
    // all, which is why the row list is a union.
    expect($rows)->toHaveKey('Idle Ivy')
        ->and($rows['Idle Ivy']['active_seconds'])->toBe(28800)
        ->and($rows['Idle Ivy']['ready_seconds'])->toBe(28800)
        ->and($rows['Idle Ivy']['total'])->toBe(0)
        ->and($rows['Idle Ivy']['answered'])->toBe(0)
        ->and($rows['Idle Ivy']['aht_seconds'])->toBeNull()
        // A real 0%, not a blank: we know her logged-in time and we know she handled
        // nothing. AP-7a blanks occupancy only when the BOTTOM of the division is zero.
        ->and($rows['Idle Ivy']['occupancy'])->toBe(0.0);
});

// --- AP-7a: occupancy, and the two ways it misbehaves ---

it('divides handling time by logged-in time for occupancy', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function (): void {
        $agent = User::factory()->create(['name' => 'Busy Bea']);

        // One hour on the floor, fifteen minutes of it handling one call.
        reportStint($agent, PresenceStatus::Ready, Carbon::today()->setTime(9, 0), Carbon::today()->setTime(10, 0));
        reportCall($agent, talk: 600, hold: 100, wrap: 200);
    });

    $this->actingAs(reportsHcUser(RoleName::HcAdmin->value));
    TenantContext::applyWebRequest(null, crossTenant: true);

    $row = productivityRows()['Busy Bea'];

    expect($row['active_seconds'])->toBe(3600)
        ->and($row['talk_seconds'])->toBe(600)
        ->and($row['hold_seconds'])->toBe(100)
        ->and($row['wrap_seconds'])->toBe(200)
        ->and($row['aht_seconds'])->toBe(900)
        // (600 + 100 + 200) / 3600 = 25%.
        ->and($row['occupancy'])->toBe(25.0);
});

it('leaves the shift blank, never zero, for an agent with calls but no shift record', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function (): void {
        $agent = User::factory()->create(['name' => 'Ghost Gus']);
        reportCall($agent, talk: 300, hold: 0, wrap: 60);
    });

    $this->actingAs(reportsHcUser(RoleName::HcAdmin->value));
    TenantContext::applyWebRequest(null, crossTenant: true);

    $row = productivityRows()['Ghost Gus'];

    // 🔴 AP-7a. A hard 0% would read as "this agent did nothing" about somebody who
    // handled a call. Blank says we hold no shift record, which is what happened.
    expect($row['total'])->toBe(1)
        ->and($row['occupancy'])->toBeNull()
        ->and($row['active_seconds'])->toBeNull()
        ->and($row['ready_seconds'])->toBeNull();
});

it('leaves the Unassigned row without a shift — it is not a person', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function (): void {
        Call::factory()->create(['agent_id' => null, 'created_at' => now()]);
    });

    $this->actingAs(reportsHcUser(RoleName::HcAdmin->value));
    TenantContext::applyWebRequest(null, crossTenant: true);

    $row = productivityRows()['Unassigned'];

    expect($row['total'])->toBe(1)
        ->and($row['active_seconds'])->toBeNull()
        ->and($row['occupancy'])->toBeNull();
});

// --- AP-2a: busiest first, then by name, and the same order twice ---

it('sorts busiest first and breaks a tie by name, the same way every read', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function (): void {
        $busy = User::factory()->create(['name' => 'Busy Bea']);
        reportCall($busy, talk: 60, hold: 0, wrap: 10);

        // Two agents tied on zero calls. Without a tiebreak they reshuffle between
        // two reads of the same screen.
        foreach (['Zoe Zephyr', 'Adam Ash'] as $name) {
            $idle = User::factory()->create(['name' => $name]);
            reportStint($idle, PresenceStatus::Ready, Carbon::today()->setTime(9, 0), Carbon::today()->setTime(10, 0));
        }
    });

    $this->actingAs(reportsHcUser(RoleName::HcAdmin->value));
    TenantContext::applyWebRequest(null, crossTenant: true);

    $order = fn (): array => array_column(
        Livewire::test(AgentProductivityReport::class)->instance()->rows(),
        'agent',
    );

    expect($order())->toBe(['Busy Bea', 'Adam Ash', 'Zoe Zephyr'])
        ->and($order())->toBe(['Busy Bea', 'Adam Ash', 'Zoe Zephyr']);
});

// --- AP-12: a blank or cleared filter reads today, never every record ever ---

it('reads today when both date boxes are cleared', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function (): void {
        $agent = User::factory()->create(['name' => 'Busy Bea']);

        reportCall($agent, talk: 60, hold: 0, wrap: 10);
        Call::factory()->forAgent($agent)->create(['created_at' => Carbon::today()->subMonth()]);
    });

    $this->actingAs(reportsHcUser(RoleName::HcAdmin->value));
    TenantContext::applyWebRequest(null, crossTenant: true);

    // Empty strings are what a CLEARED date box sends — the case a form default would
    // miss, because a form default only fires on first load.
    expect(productivityRows(['startDate' => '', 'endDate' => ''])['Busy Bea']['total'])->toBe(1)
        ->and(productivityRows()['Busy Bea']['total'])->toBe(1);

    // The month-old call is real, and an explicit range still finds it.
    $wide = productivityRows([
        'startDate' => Carbon::today()->subYear()->toDateString(),
        'endDate' => Carbon::today()->toDateString(),
    ]);

    expect($wide['Busy Bea']['total'])->toBe(2);
});

it('reads today when the shift has no calls beside it', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function (): void {
        $agent = User::factory()->create(['name' => 'Idle Ivy']);

        // Yesterday's shift must not appear in today's default view.
        reportStint($agent, PresenceStatus::Ready, Carbon::yesterday()->setTime(9, 0), Carbon::yesterday()->setTime(17, 0));
    });

    $this->actingAs(reportsHcUser(RoleName::HcAdmin->value));
    TenantContext::applyWebRequest(null, crossTenant: true);

    expect(productivityRows())->toBe([]);
});

// --- AP-13: the labels and the notes the decisions above owe the manager ---

it('renders the shift columns, the two-word labels and the notes', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function (): void {
        $worked = User::factory()->create(['name' => 'Busy Bea']);
        reportStint($worked, PresenceStatus::Ready, Carbon::today()->setTime(9, 0), Carbon::today()->setTime(10, 0));
        reportCall($worked, talk: 600, hold: 100, wrap: 200);

        // Calls but no shift record — the row that earns the third note under the table.
        reportCall(User::factory()->create(['name' => 'Ghost Gus']), talk: 300, hold: 0, wrap: 60);
    });

    $this->actingAs(reportsHcUser(RoleName::HcAdmin->value));
    TenantContext::applyWebRequest(null, crossTenant: true);

    Livewire::test(AgentProductivityReport::class)
        ->assertOk()
        ->assertSee('Shift')
        ->assertSee('Occupancy')
        ->assertSee('25.0%')          // AP-7a, on the row that has a shift
        ->assertSee('01:00:00')       // the logged-in hour, in the house clock
        ->assertSee('includes ring and hold') // AP-13, on the On-a-call column
        ->assertSee('per call')               // AP-13, on the Wrap column
        ->assertSee('status')                 // AP-13, on the Wrapping-up column
        ->assertSee('counts as Talk for the agent who did not press Hold') // AP-8 note
        ->assertSee('A day runs from midnight to midnight')                // AP-11 note, rebuilt S118
        ->assertSee('we hold no shift record for those dates');            // AP-13 note 3
});

// --- RP-5: the CSV carries every new column, in the table's order ---

it('exports the shift columns and the handling time alongside the counts', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function (): void {
        $agent = User::factory()->create(['name' => 'Busy Bea']);
        reportStint($agent, PresenceStatus::Ready, Carbon::today()->setTime(9, 0), Carbon::today()->setTime(10, 0));
        reportCall($agent, talk: 600, hold: 100, wrap: 200);
    });

    $this->actingAs(reportsHcUser(RoleName::HcAdmin->value));
    TenantContext::applyWebRequest(null, crossTenant: true);

    ob_start();
    Livewire::test(AgentProductivityReport::class)->instance()->exportCsv()->sendContent();
    $csv = ob_get_clean();

    // Shift (logged-in, ready, on-call, wrapping up, break, occupancy), then the
    // counts, then talk / hold / wrap / AHT. Durations go out as SECONDS so a
    // supervisor can total the column in a spreadsheet.
    expect($csv)->toContain('"Busy Bea",3600,3600,0,0,0,25,1,1,0,1,0,0,0,0,0,600,100,200,900');
});

// ---------------------------------------------------------------------------
// S117 — the three defects the slice 2 review found, each with the reader the
// staging test does not cover. All three passed CP-APR-1's steps while broken.
// ---------------------------------------------------------------------------

it('survives a stint whose agent was deleted', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function (): void {
        $agent = User::factory()->create(['name' => 'Gone Gary']);
        reportStint($agent, PresenceStatus::Ready, Carbon::today()->setTime(9, 0), Carbon::today()->setTime(17, 0));

        // The stint table orphans a deleted user rather than erasing the evidence
        // (nullOnDelete, by design). The row stays; its user_id goes null.
        $agent->delete();
    });

    $this->actingAs(reportsHcUser(RoleName::HcAdmin->value));
    TenantContext::applyWebRequest(null, crossTenant: true);

    // The whole page threw a TypeError on the null, not just this one row.
    expect(productivityRows())->toBe([]);
});

it('prints a shift longer than a day at its real length', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function (): void {
        $agent = User::factory()->create(['name' => 'Weekly Wanda']);

        foreach (range(1, 5) as $day) {
            reportStint(
                $agent,
                PresenceStatus::Ready,
                Carbon::today()->subDays($day)->setTime(9, 0),
                Carbon::today()->subDays($day)->setTime(17, 0),
            );
        }
    });

    $this->actingAs(reportsHcUser(RoleName::HcAdmin->value));
    TenantContext::applyWebRequest(null, crossTenant: true);

    $filters = [
        'startDate' => Carbon::today()->subDays(7)->toDateString(),
        'endDate' => Carbon::today()->toDateString(),
    ];

    // Five eight-hour days. gmdate('H:i:s') wrapped this to 16:00:00 on screen while
    // the CSV read 144000 and Agent Detail read 40h 0m — three answers, one number.
    expect(productivityRows($filters)['Weekly Wanda']['active_seconds'])->toBe(144000)
        ->and(Call::asClock(144000))->toBe('40:00:00')
        ->and(Call::asClock(86400))->toBe('24:00:00')
        // Everything below a day still reads exactly as it always did.
        ->and(Call::asClock(3600))->toBe('01:00:00')
        ->and(Call::asClock(270))->toBe('04:30')
        ->and(Call::asClock(null))->toBe('—');

    Livewire::test(AgentProductivityReport::class)
        ->set('filters', $filters)
        ->assertSee('40:00:00');
});

it('narrows the shift rows to the chosen client, not only the calls', function () {
    $clientA = Tenant::factory()->create(['name' => 'Client A']);
    $clientB = Tenant::factory()->create(['name' => 'Client B']);

    foreach ([$clientA->id => 'Alice A', $clientB->id => 'Bob B'] as $tenantId => $name) {
        TenantContext::run($tenantId, function () use ($name): void {
            $agent = User::factory()->create(['name' => $name]);
            reportStint($agent, PresenceStatus::Ready, Carbon::today()->setTime(9, 0), Carbon::today()->setTime(17, 0));
        });
    }

    // The Client box is visible to a GLOBAL reader only. A per-client Team Leader is
    // pinned by RLS, so the wall test never exercised this path.
    $this->actingAs(reportsHcUser(RoleName::HcAdmin->value));
    TenantContext::applyWebRequest(null, crossTenant: true);

    $names = array_keys(productivityRows(['clientId' => (string) $clientA->id]));

    expect($names)->toBe(['Alice A']);
});
