<?php

use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::forget();
});

function adminPanel(): Panel
{
    return Filament::getPanel('admin');
}

function makeSuperAdmin(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    Role::findOrCreate('super_admin', 'web'); // no context -> global team (0)
    $user->assignRole('super_admin');

    return $user;
}

// --- panel access (canAccessPanel) ---

it('lets a super admin (no client membership) into the panel', function () {
    expect(makeSuperAdmin()->canAccessPanel(adminPanel()))->toBeTrue();
});

it('lets a user with a client membership into the panel', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->tenants()->attach(Tenant::factory()->create());

    expect($user->canAccessPanel(adminPanel()))->toBeTrue();
});

it('denies a verified user with no role and no client', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    expect($user->canAccessPanel(adminPanel()))->toBeFalse();
});

it('denies an unverified user even with a client membership', function () {
    $user = User::factory()->unverified()->create();
    $user->tenants()->attach(Tenant::factory()->create());

    expect($user->canAccessPanel(adminPanel()))->toBeFalse();
});

// --- super-admin gate bypass (Shield define_via_gate) ---

it('grants a super admin every ability via the gate', function () {
    $user = makeSuperAdmin();

    expect($user->can('view_any_role'))->toBeTrue()
        ->and($user->can('any_unmapped_ability'))->toBeTrue();
});

it('does not grant abilities to a user without permission', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    expect($user->can('view_any_role'))->toBeFalse();
});

// --- super admin is global-only, never leaks into a specific client ---

it('treats super admin as super globally but not inside a specific client', function () {
    $user = makeSuperAdmin();
    $tenant = Tenant::factory()->create();

    expect($user->hasRole('super_admin'))->toBeTrue();

    TenantContext::run($tenant->id, fn () => expect($user->fresh()->hasRole('super_admin'))->toBeFalse());
});
