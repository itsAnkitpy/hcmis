<?php

use App\Enums\RoleName;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Models\Campaign;
use App\Models\Disposition;
use App\Models\Lead;
use App\Models\Script;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

afterEach(function () {
    // resetWebRequest too: the column test below stamps the web marker with
    // plain SET, which survives the RefreshDatabase transaction rollback and
    // would otherwise leak the posture into the next test on this connection.
    TenantContext::resetWebRequest();
    TenantContext::forget();
});

dataset('operationalModels', [
    'campaign' => [Campaign::class],
    'lead' => [Lead::class],
    'disposition' => [Disposition::class],
    'script' => [Script::class],
]);

/** A client-side user holding a per-client role scoped to $tenant's team. */
function clientRoleUser(Tenant $tenant, string $role): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->tenants()->attach($tenant);

    TenantContext::run($tenant->id, function () use ($user, $role) {
        Role::findOrCreate($role, 'web');
        $user->assignRole($role);
    });

    return $user;
}

/** An HC user holding a global role at the reserved global team (0). */
function hcRoleUser(string $role): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    Role::findOrCreate($role, 'web'); // no context -> global team (0)
    $user->assignRole($role);

    return $user;
}

// --- D-M4-5 role map (policy-level) ---

it('gives a team leader read + create + update but NOT delete', function () {
    $tenant = Tenant::factory()->create();
    $tl = clientRoleUser($tenant, RoleName::TeamLeader->value);

    TenantContext::run($tenant->id, function () use ($tl) {
        $tl = $tl->fresh();
        expect($tl->can('viewAny', Lead::class))->toBeTrue()
            ->and($tl->can('create', Lead::class))->toBeTrue()
            ->and($tl->can('update', Lead::class))->toBeTrue()
            ->and($tl->can('delete', Lead::class))->toBeFalse();
    });
});

it('gives QC read-only', function () {
    $tenant = Tenant::factory()->create();
    $qc = clientRoleUser($tenant, RoleName::Qc->value);

    TenantContext::run($tenant->id, function () use ($qc) {
        $qc = $qc->fresh();
        expect($qc->can('viewAny', Lead::class))->toBeTrue()
            ->and($qc->can('create', Lead::class))->toBeFalse()
            ->and($qc->can('update', Lead::class))->toBeFalse()
            ->and($qc->can('delete', Lead::class))->toBeFalse();
    });
});

it('keeps agents out of the operational resources entirely', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientRoleUser($tenant, RoleName::Agent->value);

    TenantContext::run($tenant->id, function () use ($agent) {
        $agent = $agent->fresh();
        expect($agent->can('viewAny', Lead::class))->toBeFalse()
            ->and($agent->can('create', Lead::class))->toBeFalse();
    });
});

it('gives an ops manager full access via the policy', function () {
    $admin = hcRoleUser(RoleName::OpsManager->value);

    expect($admin->can('viewAny', Lead::class))->toBeTrue()
        ->and($admin->can('create', Lead::class))->toBeTrue()
        ->and($admin->can('update', Lead::class))->toBeTrue()
        ->and($admin->can('delete', Lead::class))->toBeTrue();
});

it('wires the shared D-M4-5 policy to every operational model', function (string $model) {
    $tenant = Tenant::factory()->create();
    $qc = clientRoleUser($tenant, RoleName::Qc->value);
    $agent = clientRoleUser($tenant, RoleName::Agent->value);

    TenantContext::run($tenant->id, function () use ($qc, $agent, $model) {
        expect($qc->fresh()->can('viewAny', $model))->toBeTrue()      // read-only role can see it
            ->and($agent->fresh()->can('viewAny', $model))->toBeFalse(); // agent cannot
    });
})->with('operationalModels');

// --- create gate: tenant-owned data needs a current client (M4.C) ---

it('blocks the create page for a context-less super admin', function () {
    // super_admin passes canAccessPanel + Gate::before, but has no current
    // client — canCreate() still gates on TenantContext, so no crash, just 403.
    $admin = hcRoleUser(RoleName::SuperAdmin->value);

    $this->actingAs($admin)->get('/admin/campaigns/create')->assertForbidden();
});

it('allows the create page for a team leader in their client', function () {
    $tenant = Tenant::factory()->create();
    $tl = clientRoleUser($tenant, RoleName::TeamLeader->value);

    $this->actingAs($tl)->get('/admin/campaigns/create')->assertSuccessful();
});

it('forbids the create page for QC (read-only) even with a client', function () {
    $tenant = Tenant::factory()->create();
    $qc = clientRoleUser($tenant, RoleName::Qc->value);

    $this->actingAs($qc)->get('/admin/leads/create')->assertForbidden();
});

// --- list access end-to-end through the panel ---

it('lets a team leader open the leads list', function () {
    $tenant = Tenant::factory()->create();
    $tl = clientRoleUser($tenant, RoleName::TeamLeader->value);

    $this->actingAs($tl)->get('/admin/leads')->assertSuccessful();
});

it('forbids the leads list for an agent', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientRoleUser($tenant, RoleName::Agent->value);

    $this->actingAs($agent)->get('/admin/leads')->assertForbidden();
});

// --- client-attribution column (all-clients posture only) ---

it('shows the client column to global staff and hides it for client-scoped staff', function () {
    $tenant = Tenant::factory()->create();
    $lead = TenantContext::run(
        $tenant->id,
        fn () => Lead::factory()->forCampaign(Campaign::factory()->create())->create(),
    );

    // Global super admin, all-clients posture: the Client column renders.
    $this->actingAs(hcRoleUser(RoleName::SuperAdmin->value)->fresh());
    TenantContext::applyWebRequest(null, crossTenant: true);

    Livewire::test(ListLeads::class)
        ->assertCanSeeTableRecords([$lead])
        ->assertCanRenderTableColumn('tenant.name');

    // Client-scoped team leader pinned to one client: the column is redundant, hidden.
    $this->actingAs(clientRoleUser($tenant, RoleName::TeamLeader->value)->fresh());
    TenantContext::applyWebRequest($tenant->id, crossTenant: false);

    Livewire::test(ListLeads::class)
        ->assertCanSeeTableRecords([$lead])
        ->assertTableColumnHidden('tenant.name');
});
