<?php

use App\Enums\RoleName;
use App\Models\Tenant;
use App\Tenancy\Actions\ProvisionTenantRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('provisions exactly the 5 per-client roles scoped to the tenant team', function () {
    $tenant = Tenant::factory()->create();

    $rolesForTenant = Role::query()->where('team_id', $tenant->getKey())->get();

    expect($rolesForTenant->pluck('name')->sort()->values()->all())
        ->toBe(collect(RoleName::perClientValues())->sort()->values()->all());
});

it('does not leak any per-client roles into team 0', function () {
    Tenant::factory()->create();
    Tenant::factory()->create();

    $strayInGlobalTeam = Role::query()
        ->where('team_id', 0)
        ->whereIn('name', RoleName::perClientValues())
        ->count();

    expect($strayInGlobalTeam)->toBe(0);
});

it('is idempotent — re-running yields no duplicate rows', function () {
    $tenant = Tenant::factory()->create();
    $countBefore = Role::query()->where('team_id', $tenant->getKey())->count();

    ProvisionTenantRoles::run($tenant);
    ProvisionTenantRoles::run($tenant);

    $countAfter = Role::query()->where('team_id', $tenant->getKey())->count();

    expect($countAfter)->toBe($countBefore);
});

it('keeps each tenants role set in its own team', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    $expected = collect(RoleName::perClientValues())->sort()->values()->all();
    $teamA = Role::query()->where('team_id', $a->getKey())->pluck('name')->sort()->values()->all();
    $teamB = Role::query()->where('team_id', $b->getKey())->pluck('name')->sort()->values()->all();

    expect($teamA)->toBe($expected)
        ->and($teamB)->toBe($expected)
        ->and($a->getKey())->not->toBe($b->getKey());
});
