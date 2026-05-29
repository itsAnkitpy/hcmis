<?php

use App\Enums\RoleName;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\RelationManagers\TenantsRelationManager;
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

    foreach (RoleName::globals() as $role) {
        Role::findOrCreate($role->value, 'web');
    }

    $admin = User::factory()->create(['email_verified_at' => now()]);
    $admin->assignRole(RoleName::SuperAdmin->value);
    $this->actingAs($admin);

    $this->user = User::factory()->create(['email_verified_at' => now()]);
});

afterEach(function () {
    TenantContext::forget();
});

it('attaches an existing tenant with a per-tenant role (lands in tenant team, not team 0)', function () {
    $tenant = Tenant::factory()->create();

    Livewire::test(TenantsRelationManager::class, [
        'ownerRecord' => $this->user,
        'pageClass' => EditUser::class,
    ])
        ->callTableAction('attach', data: [
            'recordId' => (string) $tenant->getKey(),
            'role_name' => RoleName::Qc->value,
        ])
        ->assertHasNoTableActionErrors();

    expect($this->user->tenants()->whereKey($tenant->getKey())->exists())->toBeTrue();

    TenantContext::run($tenant->getKey(), function () {
        expect($this->user->fresh()->hasRole(RoleName::Qc->value))->toBeTrue();
    });

    $teamZeroForUser = DB::table('model_has_roles')
        ->where('model_id', $this->user->getKey())
        ->where('team_id', 0)
        ->count();
    expect($teamZeroForUser)->toBe(0);
});

it('changes the user\'s role on a given tenant', function () {
    $tenant = Tenant::factory()->create();
    $this->user->tenants()->attach($tenant);
    TenantContext::run($tenant->getKey(), fn () => $this->user->assignRole(RoleName::Agent->value));

    Livewire::test(TenantsRelationManager::class, [
        'ownerRecord' => $this->user,
        'pageClass' => EditUser::class,
    ])
        ->callTableAction('change_role', $tenant, data: [
            'role_name' => RoleName::Trainer->value,
        ])
        ->assertHasNoTableActionErrors();

    TenantContext::run($tenant->getKey(), function () {
        $fresh = $this->user->fresh();
        expect($fresh->hasRole(RoleName::Trainer->value))->toBeTrue()
            ->and($fresh->hasRole(RoleName::Agent->value))->toBeFalse();
    });
});

it('detaches a tenant — strips pivot AND per-team role rows', function () {
    $tenant = Tenant::factory()->create();
    $this->user->tenants()->attach($tenant);
    TenantContext::run($tenant->getKey(), fn () => $this->user->assignRole(RoleName::Agent->value));

    Livewire::test(TenantsRelationManager::class, [
        'ownerRecord' => $this->user,
        'pageClass' => EditUser::class,
    ])
        ->callTableAction('detach', $tenant)
        ->assertHasNoTableActionErrors();

    expect($this->user->tenants()->whereKey($tenant->getKey())->exists())->toBeFalse();

    $rolesLeft = DB::table('model_has_roles')
        ->where('model_id', $this->user->getKey())
        ->where('team_id', $tenant->getKey())
        ->count();
    expect($rolesLeft)->toBe(0);
});
