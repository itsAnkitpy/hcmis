<?php

use App\Http\Middleware\SetCurrentTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantSwitcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::forget();
});

/**
 * Run the SetCurrentTenant middleware for a user and return the tenant id that
 * was bound into TenantContext while the request was being handled.
 */
function tenantBoundDuringRequest(User $user, ?int $sessionTenantId = null): ?int
{
    $session = app('session')->driver();
    $session->start();

    if ($sessionTenantId !== null) {
        $session->put('current_tenant_id', $sessionTenantId);
    }

    $request = Request::create('/admin', 'GET');
    $request->setLaravelSession($session);
    $request->setUserResolver(fn () => $user);

    $bound = null;

    (new SetCurrentTenant)->handle($request, function () use (&$bound) {
        $bound = TenantContext::id();

        return new Response;
    });

    return $bound;
}

// --- membership model ---

it('knows which clients a user may access', function () {
    $user = User::factory()->create();
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $user->tenants()->attach($a);

    expect($user->mayAccessTenant($a))->toBeTrue()
        ->and($user->mayAccessTenant($b))->toBeFalse()
        ->and($user->defaultTenant()->is($a))->toBeTrue();
});

// --- the switch action ---

it('switches to an allowed client and records it', function () {
    $user = User::factory()->create();
    $a = Tenant::factory()->create();
    $user->tenants()->attach($a);

    expect(TenantSwitcher::switchTo($user, $a))->toBeTrue()
        ->and(session('current_tenant_id'))->toBe($a->id);
});

it('refuses to switch to a client the user may not access', function () {
    $user = User::factory()->create();
    $forbidden = Tenant::factory()->create();

    expect(TenantSwitcher::switchTo($user, $forbidden))->toBeFalse()
        ->and(session('current_tenant_id'))->toBeNull();
});

// --- the request bridge (R-06 seam) ---

it('binds the default client when the session has no choice', function () {
    $user = User::factory()->create();
    $a = Tenant::factory()->create();
    $user->tenants()->attach($a);

    expect(tenantBoundDuringRequest($user))->toBe($a->id);
});

it('honors a valid current-client choice from the session', function () {
    $user = User::factory()->create();
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $user->tenants()->attach([$a->id, $b->id]);

    expect(tenantBoundDuringRequest($user, $b->id))->toBe($b->id);
});

it('rejects a tampered current-client and falls back to an allowed one', function () {
    $user = User::factory()->create();
    $a = Tenant::factory()->create();
    $forbidden = Tenant::factory()->create(); // user is NOT a member

    $user->tenants()->attach($a);

    // Session claims a client the user may not access -> must NOT be bound.
    expect(tenantBoundDuringRequest($user, $forbidden->id))->toBe($a->id);
});

it('binds no client for a user that belongs to none', function () {
    $user = User::factory()->create();
    $forbidden = Tenant::factory()->create();

    expect(tenantBoundDuringRequest($user, $forbidden->id))->toBeNull();
});

it('never pins a global-role user to a client, even with a membership and a session choice', function () {
    $user = User::factory()->create();
    Role::findOrCreate('super_admin', 'web'); // global team (0)
    $user->assignRole('super_admin');

    // Even if a super admin somehow holds a membership and the session names it,
    // the bridge must leave them global so their global-team role stays visible.
    $tenant = Tenant::factory()->create();
    $user->tenants()->attach($tenant);

    expect(tenantBoundDuringRequest($user, $tenant->id))->toBeNull();
});

// --- per-client roles via the team resolver ---

it('scopes a role to the client it was granted in', function () {
    $user = User::factory()->create();
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $user->tenants()->attach([$a->id, $b->id]);

    // M3 TenantObserver auto-provisions the per-client roles in each team —
    // grab the team_leader role for A and assign it; B has its own copy so
    // the role exists there too, just unassigned.
    TenantContext::run($a->id, function () use ($user, $a) {
        $role = Role::query()->where(['name' => 'team_leader', 'team_id' => $a->id])->firstOrFail();
        $user->assignRole($role);
    });

    TenantContext::run($a->id, fn () => expect($user->fresh()->hasRole('team_leader'))->toBeTrue());
    TenantContext::run($b->id, fn () => expect($user->fresh()->hasRole('team_leader'))->toBeFalse());
});

// --- M3 lifecycle guard: non-active tenants never bound ---

it('refuses to bind a suspended tenant and falls back to an active one', function () {
    $user = User::factory()->create();
    $suspended = Tenant::factory()->suspended()->create();
    $active = Tenant::factory()->create();

    $user->tenants()->attach([$suspended->id, $active->id]);

    // Even if the user's session names the suspended client, the bridge must
    // route them to the active fallback — suspended/archived tenants never
    // get bound into TenantContext.
    expect(tenantBoundDuringRequest($user, $suspended->id))->toBe($active->id);
});

it('binds no client when every membership is non-active', function () {
    $user = User::factory()->create();
    $suspended = Tenant::factory()->suspended()->create();
    $archived = Tenant::factory()->archived()->create();

    $user->tenants()->attach([$suspended->id, $archived->id]);

    expect(tenantBoundDuringRequest($user, $suspended->id))->toBeNull();
});
