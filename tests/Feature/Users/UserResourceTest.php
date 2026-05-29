<?php

use App\Enums\RoleName;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    TenantContext::forget();

    foreach (RoleName::globals() as $role) {
        Role::findOrCreate($role->value, 'web');
    }

    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    $this->admin->assignRole(RoleName::SuperAdmin->value);
    $this->actingAs($this->admin);
});

afterEach(function () {
    TenantContext::forget();
});

it('mounts the Users list page', function () {
    Livewire::test(ListUsers::class)->assertSuccessful();
});

it('requires an HC role when creating a user (global-only page)', function () {
    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Plain Jane',
            'email' => 'plain@example.test',
        ])
        ->call('create')
        ->assertHasFormErrors(['global_role']);

    expect(User::query()->where('email', 'plain@example.test')->exists())->toBeFalse();
});

it('creates a user with an initial global role', function () {
    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'HC Admin',
            'email' => 'hcadmin@example.test',
            'global_role' => RoleName::HcAdmin->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $user = User::query()->where('email', 'hcadmin@example.test')->firstOrFail();

    TenantContext::forget();
    expect($user->hasRole(RoleName::HcAdmin->value))->toBeTrue();
});

// NOTE: creating a user dropped straight onto a tenant is no longer done from
// the global Users page (it's global-only now). That path lives in the client's
// "Users" tab — covered by UsersRelationManagerTest ("add new").

it('marks a user as email-verified via the Edit toggle', function () {
    $user = User::factory()->create(['email_verified_at' => null]);

    Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
        ->fillForm(['email_verified' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->fresh()->email_verified_at)->not->toBeNull();
});

it('syncs global roles via the Edit form', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    // Start: no global roles. Add HcAdmin and OpsManager.
    Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
        ->fillForm([
            'email_verified' => true,
            'global_roles' => [RoleName::HcAdmin->value, RoleName::OpsManager->value],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    TenantContext::forget();
    expect($user->fresh()->hasRole(RoleName::HcAdmin->value))->toBeTrue()
        ->and($user->fresh()->hasRole(RoleName::OpsManager->value))->toBeTrue()
        ->and($user->fresh()->hasRole(RoleName::SuperAdmin->value))->toBeFalse();

    // Now drop OpsManager.
    Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
        ->fillForm([
            'email_verified' => true,
            'global_roles' => [RoleName::HcAdmin->value],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    TenantContext::forget();
    expect($user->fresh()->hasRole(RoleName::HcAdmin->value))->toBeTrue()
        ->and($user->fresh()->hasRole(RoleName::OpsManager->value))->toBeFalse();
});

it('refuses to remove super_admin from the last super admin', function () {
    // $this->admin is the only super_admin (set in beforeEach).
    Livewire::test(EditUser::class, ['record' => $this->admin->getRouteKey()])
        ->fillForm([
            'email_verified' => true,
            'global_roles' => [], // try to strip super_admin
        ])
        ->call('save');

    $stillSuperAdmin = DB::table('model_has_roles')
        ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
        ->where('model_has_roles.model_id', $this->admin->getKey())
        ->where('model_has_roles.team_id', 0)
        ->where('roles.name', RoleName::SuperAdmin->value)
        ->exists();

    expect($stillSuperAdmin)->toBeTrue();
});

it('allows removing super_admin when another super admin remains', function () {
    $second = User::factory()->create(['email_verified_at' => now()]);
    TenantContext::forget();
    $second->assignRole(RoleName::SuperAdmin->value);

    Livewire::test(EditUser::class, ['record' => $second->getRouteKey()])
        ->fillForm([
            'email_verified' => true,
            'global_roles' => [], // drop super_admin; $this->admin still holds it
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $secondStillSuperAdmin = DB::table('model_has_roles')
        ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
        ->where('model_has_roles.model_id', $second->getKey())
        ->where('model_has_roles.team_id', 0)
        ->where('roles.name', RoleName::SuperAdmin->value)
        ->exists();

    expect($secondStillSuperAdmin)->toBeFalse();
});
