<?php

use App\Enums\RoleName;
use App\Filament\Resources\Tenants\Pages\ListTenants;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    TenantContext::forget();

    foreach (RoleName::globals() as $role) {
        Role::findOrCreate($role->value, 'web');
    }

    $this->superAdmin = User::factory()->create(['email_verified_at' => now()]);
    $this->superAdmin->assignRole(RoleName::SuperAdmin->value);

    // A client agent: verified + a client membership (so they DO pass
    // canAccessPanel) but hold only a per-client role — not a global one.
    $this->tenant = Tenant::factory()->create(); // observer provisions per-client roles
    $this->agent = User::factory()->create(['email_verified_at' => now()]);
    $this->agent->tenants()->attach($this->tenant);
    TenantContext::run($this->tenant->id, fn () => $this->agent->assignRole(RoleName::Agent->value));
});

afterEach(fn () => TenantContext::forget());

it('blocks a client agent from the global Users resource', function () {
    $this->actingAs($this->agent)
        ->get('/admin/users')
        ->assertForbidden();
});

it('blocks a client agent from the Tenants resource', function () {
    $this->actingAs($this->agent)
        ->get('/admin/tenants')
        ->assertForbidden();
});

it('blocks a client agent from opening a user edit page by URL (no self-escalation)', function () {
    $this->actingAs($this->agent)
        ->get('/admin/users/'.$this->superAdmin->getKey().'/edit')
        ->assertForbidden();
});

it('lets HC global staff into the Users and Tenants resources', function () {
    $this->actingAs($this->superAdmin);

    Livewire::test(ListUsers::class)->assertSuccessful();
    Livewire::test(ListTenants::class)->assertSuccessful();
});
