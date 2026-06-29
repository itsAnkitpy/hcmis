<?php

use App\Enums\RoleName;
use App\Filament\Resources\Calls\CallResource;
use App\Filament\Resources\Calls\Pages\ListCalls;
use App\Models\Call;
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
function callReviewHcUser(string $role): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    Role::findOrCreate($role, 'web');
    $user->assignRole($role);

    return $user;
}

// --- CR-3: the read gate ---

it('grants call-review read to global staff', function (string $role) {
    expect(callReviewHcUser($role)->can('viewAny', Call::class))->toBeTrue();
})->with([
    RoleName::SuperAdmin->value,
    RoleName::HcAdmin->value,
    RoleName::OpsManager->value,
]);

it('grants call-review read to the per-client auditors (team leader + QC)', function (string $role) {
    $tenant = Tenant::factory()->create();
    $user = clientUserWithRole($tenant, $role);

    TenantContext::run($tenant->id, function () use ($user) {
        expect($user->fresh()->can('viewAny', Call::class))->toBeTrue();
    });
})->with([
    RoleName::TeamLeader->value,
    RoleName::Qc->value,
]);

it('denies call-review read to agent, trainer and client user', function (string $role) {
    $tenant = Tenant::factory()->create();
    $user = clientUserWithRole($tenant, $role);

    TenantContext::run($tenant->id, function () use ($user) {
        expect($user->fresh()->can('viewAny', Call::class))->toBeFalse();
    });
})->with([
    RoleName::Agent->value,
    RoleName::Trainer->value,
    RoleName::ClientUser->value,
]);

// --- CR-1: immutable — write/delete denied even to a permitted reader ---

it('denies create, update and delete to a permitted reader', function () {
    // hc_admin (not super_admin, so Shield's Gate::before does not short-circuit):
    // the policy itself must deny every mutation.
    $admin = callReviewHcUser(RoleName::HcAdmin->value);

    expect($admin->can('create', Call::class))->toBeFalse()
        ->and($admin->can('update', Call::class))->toBeFalse()
        ->and($admin->can('delete', Call::class))->toBeFalse();
});

it('exposes only the read-only list and view pages', function () {
    expect(array_keys(CallResource::getPages()))->toEqualCanonicalizing(['index', 'view']);
});

// --- the list page loads for a permitted user ---

it('loads the call-review list for a permitted user', function () {
    $admin = callReviewHcUser(RoleName::HcAdmin->value);

    $this->actingAs($admin);
    TenantContext::applyWebRequest(null, crossTenant: true);

    Livewire::test(ListCalls::class)->assertOk();
});
