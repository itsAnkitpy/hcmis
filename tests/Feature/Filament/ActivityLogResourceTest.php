<?php

use App\Enums\RoleName;
use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Models\ActivityLog;
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

/** An HC user holding a global role at the reserved global team (0). */
function auditHcUser(string $role): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    Role::findOrCreate($role, 'web');
    $user->assignRole($role);

    return $user;
}

// --- D-M7-4: read gate ---

it('grants audit read to global staff', function (string $role) {
    expect(auditHcUser($role)->can('viewAny', ActivityLog::class))->toBeTrue();
})->with([
    RoleName::SuperAdmin->value,
    RoleName::HcAdmin->value,
    RoleName::OpsManager->value,
]);

it('grants audit read to QC (the compliance reader)', function () {
    $tenant = Tenant::factory()->create();
    $qc = clientUserWithRole($tenant, RoleName::Qc->value);

    TenantContext::run($tenant->id, function () use ($qc) {
        expect($qc->fresh()->can('viewAny', ActivityLog::class))->toBeTrue();
    });
});

it('denies audit read to team leader, trainer and agent', function (string $role) {
    $tenant = Tenant::factory()->create();
    $user = clientUserWithRole($tenant, $role);

    TenantContext::run($tenant->id, function () use ($user) {
        expect($user->fresh()->can('viewAny', ActivityLog::class))->toBeFalse();
    });
})->with([
    RoleName::TeamLeader->value,
    RoleName::Trainer->value,
    RoleName::Agent->value,
]);

// --- D-M7-5: immutable at the policy layer (write/delete denied) ---

it('denies create, update and delete to a permitted reader', function () {
    // hc_admin (not super_admin, so Shield's Gate::before does not short-circuit):
    // the policy itself must deny every mutation.
    $admin = auditHcUser(RoleName::HcAdmin->value);

    expect($admin->can('create', ActivityLog::class))->toBeFalse()
        ->and($admin->can('update', ActivityLog::class))->toBeFalse()
        ->and($admin->can('delete', ActivityLog::class))->toBeFalse();
});

// --- the list page loads for a permitted user ---

it('loads the audit list for a permitted user', function () {
    $admin = auditHcUser(RoleName::HcAdmin->value);

    $this->actingAs($admin);
    TenantContext::applyWebRequest(null, crossTenant: true);

    Livewire::test(ListActivityLogs::class)->assertOk();
});
