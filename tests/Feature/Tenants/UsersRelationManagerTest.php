<?php

use App\Enums\RoleName;
use App\Filament\Resources\Tenants\Pages\EditTenant;
use App\Filament\Resources\Tenants\RelationManagers\UsersRelationManager;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    TenantContext::forget();

    $admin = User::factory()->create(['email_verified_at' => now()]);
    Role::findOrCreate(RoleName::SuperAdmin->value, 'web');
    $admin->assignRole(RoleName::SuperAdmin->value);
    $this->actingAs($admin);

    $this->tenant = Tenant::factory()->create();
});

afterEach(function () {
    TenantContext::forget();
});

it('adds a brand-new user to the tenant with the right role and team', function () {
    Livewire::test(UsersRelationManager::class, [
        'ownerRecord' => $this->tenant,
        'pageClass' => EditTenant::class,
    ])
        ->callTableAction('add_new', data: [
            'name' => 'Riya Sharma',
            'email' => 'riya@example.test',
            'role_name' => RoleName::TeamLeader->value,
        ])
        ->assertHasNoTableActionErrors();

    $user = User::query()->where('email', 'riya@example.test')->firstOrFail();

    expect($this->tenant->users()->whereKey($user->getKey())->exists())->toBeTrue()
        ->and($user->email_verified_at)->toBeNull();

    TenantContext::run($this->tenant->getKey(), function () use ($user) {
        expect($user->fresh()->hasRole(RoleName::TeamLeader->value))->toBeTrue();
    });

    $teamZeroForUser = DB::table('model_has_roles')
        ->where('model_id', $user->getKey())
        ->where('team_id', 0)
        ->count();
    expect($teamZeroForUser)->toBe(0);
});

it('attaches an existing user and gives them a per-tenant role', function () {
    $existing = User::factory()->create(['email' => 'arjun@example.test']);

    Livewire::test(UsersRelationManager::class, [
        'ownerRecord' => $this->tenant,
        'pageClass' => EditTenant::class,
    ])
        ->callTableAction('attach', data: [
            'recordId' => (string) $existing->getKey(),
            'role_name' => RoleName::Qc->value,
        ])
        ->assertHasNoTableActionErrors();

    expect($this->tenant->users()->whereKey($existing->getKey())->exists())->toBeTrue();

    TenantContext::run($this->tenant->getKey(), function () use ($existing) {
        expect($existing->fresh()->hasRole(RoleName::Qc->value))->toBeTrue();
    });
});

it('changes a users role on this tenant, replacing the previous one', function () {
    $user = User::factory()->create();
    $this->tenant->users()->attach($user);
    TenantContext::run($this->tenant->getKey(), fn () => $user->assignRole(RoleName::Agent->value));

    Livewire::test(UsersRelationManager::class, [
        'ownerRecord' => $this->tenant,
        'pageClass' => EditTenant::class,
    ])
        ->callTableAction('change_role', $user, data: [
            'role_name' => RoleName::Trainer->value,
        ])
        ->assertHasNoTableActionErrors();

    TenantContext::run($this->tenant->getKey(), function () use ($user) {
        $fresh = $user->fresh();
        expect($fresh->hasRole(RoleName::Trainer->value))->toBeTrue()
            ->and($fresh->hasRole(RoleName::Agent->value))->toBeFalse();
    });
});

it('detaches a user — removes the pivot row AND the per-team role assignment', function () {
    $user = User::factory()->create();
    $this->tenant->users()->attach($user);
    TenantContext::run($this->tenant->getKey(), fn () => $user->assignRole(RoleName::Agent->value));

    Livewire::test(UsersRelationManager::class, [
        'ownerRecord' => $this->tenant,
        'pageClass' => EditTenant::class,
    ])
        ->callTableAction('detach', $user)
        ->assertHasNoTableActionErrors();

    expect($this->tenant->users()->whereKey($user->getKey())->exists())->toBeFalse();

    $rolesLeftInTenantTeam = DB::table('model_has_roles')
        ->where('model_id', $user->getKey())
        ->where('team_id', $this->tenant->getKey())
        ->count();
    expect($rolesLeftInTenantTeam)->toBe(0);
});

it('does not show the global super-admin in another tenants user list', function () {
    // The super-admin from beforeEach holds no tenant membership; not in any tenant's list.
    Livewire::test(UsersRelationManager::class, [
        'ownerRecord' => $this->tenant,
        'pageClass' => EditTenant::class,
    ])
        ->assertCanNotSeeTableRecords([auth()->user()]);
});
