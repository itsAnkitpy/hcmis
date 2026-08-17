<?php

use App\Enums\PresenceStatus;
use App\Enums\RoleName;
use App\Enums\StintEndedVia;
use App\Filament\Pages\AgentDetail;
use App\Filament\Pages\Reports\AgentProductivityReport;
use App\Filament\Resources\Calls\Pages\ListCalls;
use App\Models\AgentStatusHistory;
use App\Models\Call;
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
 * S118 — the client's own clock, on the report screens.
 *
 * The Call Export has cut and printed its days on the client's clock since S108 (CE-10 /
 * CE-10a). The screens ran on the application clock, which is UTC, so the same chosen day
 * named two different sets of calls in two places — five and a half hours apart at each
 * edge for an Indian floor. These tests pin the screens to the export's answer.
 *
 * 🔴 EVERY FROZEN MOMENT IS HANDED OVER AS UTC. Carbon's test clock lends its own zone to
 * dates read back out of the database, so freezing on an India-time object makes every
 * stored timestamp read five and a half hours early. That is a test artefact with no
 * production counterpart, and it costs an afternoon to find.
 */

/** A wall-clock moment on an India-time floor, as the UTC instant the tables hold. */
function indiaMoment(string $wallClock): Carbon
{
    return Carbon::parse($wallClock, 'Asia/Kolkata')->utc();
}

function indiaClient(): Tenant
{
    return Tenant::factory()->create(['timezone' => 'Asia/Kolkata']);
}

// ------------------------------------------------------------------ where the day is cut

it('cuts the report day on the client clock, at both edges', function () {
    $tenant = indiaClient();

    // 🔴 THE CALLS ARE NAMED, NOT COUNTED. Moving the whole day five and a half hours
    // takes as many calls out of one end as it puts in at the other, so a test that only
    // counts them passes on the wrong set — this one did, until it named them.
    $day = [
        'Eve Early' => '2026-08-13 00:00:00',    // the client's first moment; the 12th in UTC
        'Mo Midday' => '2026-08-13 13:00:00',
        'Nel Nearly' => '2026-08-13 23:59:59',   // the client's last moment
    ];
    $outside = [
        'Pat Previous' => '2026-08-12 23:59:59', // one second before the day opens
        'Nia Next' => '2026-08-14 00:00:00',     // the next day, though UTC still says the 13th
    ];

    $names = TenantContext::run($tenant->id, function () use ($day, $outside): array {
        foreach ($day + $outside as $name => $wallClock) {
            $agent = User::factory()->create(['name' => $name]);
            Call::factory()->forAgent($agent)->create(['created_at' => indiaMoment($wallClock)]);
        }

        $rows = app(CallReportService::class)->agentProductivity(CallReportFilters::fromArray([
            'startDate' => '2026-08-13',
            'endDate' => '2026-08-13',
        ]));

        return array_column($rows, 'agent');
    });

    sort($names);

    expect($names)->toBe(['Eve Early', 'Mo Midday', 'Nel Nearly']);
});

it('buckets the daily chart on the client day, so one Indian day is one bar', function () {
    $tenant = indiaClient();

    $days = TenantContext::run($tenant->id, function (): array {
        // All three are the 13th in India. The first is the 12th in UTC, so bucketing on
        // the raw stored date drew the 13th as two half-empty bars.
        Call::factory()->create(['created_at' => indiaMoment('2026-08-13 02:00')]);
        Call::factory()->create(['created_at' => indiaMoment('2026-08-13 12:00')]);
        Call::factory()->create(['created_at' => indiaMoment('2026-08-13 22:00')]);

        return app(CallReportService::class)->callsByDay(CallReportFilters::fromArray([
            'startDate' => '2026-08-13',
            'endDate' => '2026-08-13',
        ]));
    });

    expect($days)->toHaveCount(1)
        ->and($days[0]['date'])->toBe('2026-08-13')
        ->and($days[0]['total'])->toBe(3);
});

// ------------------------------------------------------------------ the blank date box

it('reads a blank date box as the client today, not ours', function () {
    // 🔴 THE TWO CLOCKS MUST NAME DIFFERENT DAYS FOR THIS TO PROVE ANYTHING. Stand at
    // 09:00 in India, which is 03:30 in UTC on the same date, and take a call at 02:00
    // that morning — 20:30 the previous evening in UTC. It belongs to the client's today
    // and not to ours, so a report opening on UTC today loses the whole early shift.
    $this->travelTo(indiaMoment('2026-08-13 09:00'));

    $tenant = indiaClient();
    $leader = clientUserWithRole($tenant, RoleName::TeamLeader->value);

    TenantContext::run($tenant->id, function () use ($tenant, $leader): void {
        $agent = User::factory()->create(['name' => 'Eve Early']);
        $agent->tenants()->attach($tenant);

        Call::factory()->forAgent($agent)->create(['created_at' => indiaMoment('2026-08-13 02:00')]);

        $this->actingAs($leader->fresh());

        $rows = collect(Livewire::test(AgentProductivityReport::class)->instance()->rows())
            ->keyBy('agent')
            ->all();

        expect($rows)->toHaveKey('Eve Early')
            ->and($rows['Eve Early']['total'])->toBe(1);
    });
});

// ------------------------------------------------------------------ what is printed

it('prints the agent detail clock times on the client clock', function () {
    $tenant = indiaClient();

    $summary = TenantContext::run($tenant->id, function () use ($tenant): array {
        $agent = User::factory()->create();
        $agent->tenants()->attach($tenant);

        AgentStatusHistory::factory()->forUser($agent)->create([
            'status' => PresenceStatus::Ready,
            'started_at' => indiaMoment('2026-08-13 14:55'),
            'ended_at' => indiaMoment('2026-08-13 18:20'),
            'ended_via' => StintEndedVia::Changed,
        ]);

        $page = new AgentDetail;
        $page->agentId = $agent->id;
        $page->agentName = $agent->name;
        $page->date = '2026-08-13';

        return $page->daySummary();
    });

    // The agent sat down at 14:55. The page read 09:25 — the stored UTC — until S118.
    expect($summary['firstLogin']->format('H:i'))->toBe('14:55')
        ->and($summary['lastActivity']->format('H:i'))->toBe('18:20')
        ->and($summary['timeline'][0]['startedAt']->format('H:i'))->toBe('14:55')
        ->and($summary['timeline'][0]['endedAt']->format('H:i'))->toBe('18:20')
        // Only the instants move. A duration is the same length in every zone.
        ->and($summary['seconds']['ready'])->toBe(12300);
});

// ------------------------------------------------------------------ the Calls list agrees

it('names the same calls on the Calls list as the export does for one day', function () {
    $tenant = indiaClient();
    $leader = clientUserWithRole($tenant, RoleName::TeamLeader->value);

    TenantContext::run($tenant->id, function () use ($leader): void {
        $inside = Call::factory()->create(['created_at' => indiaMoment('2026-08-13 02:00')]);
        $alsoInside = Call::factory()->create(['created_at' => indiaMoment('2026-08-13 23:30')]);
        $dayBefore = Call::factory()->create(['created_at' => indiaMoment('2026-08-12 23:00')]);
        $dayAfter = Call::factory()->create(['created_at' => indiaMoment('2026-08-14 01:00')]);

        $this->actingAs($leader->fresh());

        // `whereDate` compared the raw stored date, so this list disagreed with the
        // export by five and a half hours at each edge — the export was right.
        Livewire::test(ListCalls::class)
            ->filterTable('date', ['from' => '2026-08-13', 'until' => '2026-08-13'])
            ->assertCanSeeTableRecords([$inside, $alsoInside])
            ->assertCanNotSeeTableRecords([$dayBefore, $dayAfter]);
    });
});
