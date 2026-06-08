<?php

use App\Enums\DncSource;
use App\Enums\RoleName;
use App\Filament\Resources\DncEntries\Pages\CreateDncEntry;
use App\Models\DncEntry;
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

/**
 * A team_leader pinned to a fresh client, with the web-path context applied —
 * the posture SetCurrentTenant gives client-side staff on a panel request.
 */
function dncTeamLeader(): Tenant
{
    $tenant = Tenant::factory()->create();
    $teamLeader = clientUserWithRole($tenant, RoleName::TeamLeader->value);

    test()->actingAs($teamLeader->fresh());
    TenantContext::applyWebRequest($tenant->id, false);

    return $tenant;
}

/** A global super admin with no current client. */
function dncSuperAdmin(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    Role::findOrCreate(RoleName::SuperAdmin->value, 'web'); // no context -> global team
    $user->assignRole(RoleName::SuperAdmin->value);

    return $user->fresh();
}

// --- create through the resource: tenant-stamped + phone normalized ---

it('lets a client team leader add a DNC entry through the resource', function () {
    $tenant = dncTeamLeader();

    Livewire::test(CreateDncEntry::class)
        ->fillForm([
            'phone' => '98765 43210',
            'source' => DncSource::CustomerRequest->value,
            'reason' => 'Opted out via ticket #42',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $entry = DncEntry::query()->first();

    expect($entry)->not->toBeNull()
        ->and($entry->phone)->toBe('9876543210')              // normalized on save
        ->and($entry->tenant_id)->toBe($tenant->id)           // stamped to the active client
        ->and($entry->source)->toBe(DncSource::CustomerRequest);
});

// --- D-M6-4 through the form: same-client duplicate blocked, cross-client ok ---

it('enforces one number per client but allows the same number for another client', function () {
    // Another client already lists the number — this must NOT block us.
    $other = Tenant::factory()->create();
    TenantContext::run($other->id, fn () => DncEntry::factory()->create(['phone' => '9876543210']));

    dncTeamLeader();

    // First add for our client succeeds even though another client has the number.
    Livewire::test(CreateDncEntry::class)
        ->fillForm(['phone' => '9876543210', 'source' => DncSource::Manual->value])
        ->call('create')
        ->assertHasNoFormErrors();

    // A second add of the same number for the same client is rejected (scopedUnique).
    Livewire::test(CreateDncEntry::class)
        ->fillForm(['phone' => '9876543210', 'source' => DncSource::Manual->value])
        ->call('create')
        ->assertHasFormErrors(['phone']);

    expect(DncEntry::query()->where('phone', '9876543210')->count())->toBe(1);
});

// --- create-page gate (mirrors the M4 operational create gate) ---

it('forbids the DNC create page for a context-less super admin', function () {
    $this->actingAs(dncSuperAdmin())->get('/admin/dnc-entries/create')->assertForbidden();
});

it('allows the DNC create page for a team leader in their client', function () {
    $tenant = Tenant::factory()->create();
    $tl = clientUserWithRole($tenant, RoleName::TeamLeader->value);

    $this->actingAs($tl->fresh())->get('/admin/dnc-entries/create')->assertSuccessful();
});

it('forbids the DNC create page for read-only QC', function () {
    $tenant = Tenant::factory()->create();
    $qc = clientUserWithRole($tenant, RoleName::Qc->value);

    $this->actingAs($qc->fresh())->get('/admin/dnc-entries/create')->assertForbidden();
});

// --- list access through the panel ---

it('lets a team leader open the DNC list but keeps an agent out', function () {
    $tenant = Tenant::factory()->create();
    $tl = clientUserWithRole($tenant, RoleName::TeamLeader->value);
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $this->actingAs($tl->fresh())->get('/admin/dnc-entries')->assertSuccessful();
    $this->actingAs($agent->fresh())->get('/admin/dnc-entries')->assertForbidden();
});
