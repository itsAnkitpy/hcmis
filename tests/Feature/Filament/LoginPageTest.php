<?php

use App\Enums\RoleName;
use App\Filament\Auth\Login;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

afterEach(function () {
    // Same guard as the sibling Filament tests: the tenant marker set inside
    // clientUserWithRole() survives the RefreshDatabase rollback.
    TenantContext::resetWebRequest();
    TenantContext::forget();
});

it('renders the branded login page with the custom Login class', function () {
    $this->get('/admin/login')
        ->assertSuccessful()
        ->assertSeeLivewire(Login::class)
        ->assertSee('Welcome back');
});

it('authenticates a tenant member with valid credentials', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    Livewire::test(Login::class)
        ->fillForm([
            'email' => $agent->email,
            'password' => 'password',
        ])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect(auth()->check())->toBeTrue()
        ->and(auth()->id())->toBe($agent->id);
});

it('rejects invalid credentials and stays unauthenticated', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    Livewire::test(Login::class)
        ->fillForm([
            'email' => $agent->email,
            'password' => 'wrong-password',
        ])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    expect(auth()->check())->toBeFalse();
});
