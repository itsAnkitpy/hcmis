<?php

use App\Enums\RoleName;
use App\Filament\Pages\Reports\AgentProductivityReport;
use App\Filament\Pages\Reports\CallSummaryReport;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::resetWebRequest();
    TenantContext::forget();
});

/** An HC user holding a global role at the reserved global team. */
function reportsHcUser(string $role): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    Role::findOrCreate($role, 'web');
    $user->assignRole($role);

    return $user;
}

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
    $tenant = Tenant::factory()->create();
    seedCallReportFixture($tenant);

    $this->actingAs(reportsHcUser(RoleName::HcAdmin->value));
    TenantContext::applyWebRequest(null, crossTenant: true);

    Livewire::test(CallSummaryReport::class)
        ->assertOk()
        ->assertSee('Interested') // a disposition label from the breakdown
        ->assertSee('Sold');
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

    expect($csv)->toContain('Agent,"Total calls"')     // the header row (quoted where spaced)
        ->and($csv)->toContain('Alice,3,1,2,2,1,1,1')   // Alice's counts, in table order
        ->and($csv)->toContain('Bob,2,1,1,1,0,1,0');    // Bob's counts
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
