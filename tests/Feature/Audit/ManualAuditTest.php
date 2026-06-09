<?php

use App\Audit\Audit;
use App\Enums\RoleName;
use App\Models\ActivityLog;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

afterEach(function () {
    // resetWebRequest clears the plain-SET GUC the stale-guc test stamps, which
    // would otherwise leak the posture onto the next test on this connection.
    TenantContext::resetWebRequest();
    TenantContext::forget();
});

// --- D-M7-2: auth events ---

it('logs a successful login as an ownerless auth event caused by the user', function () {
    $user = User::factory()->create();

    event(new Login('web', $user, false));

    $activity = TenantContext::cross(fn (): ?ActivityLog => ActivityLog::query()
        ->where('log_name', 'auth')->where('event', 'login')->latest('id')->first());

    expect($activity)->not->toBeNull()
        ->and($activity->tenant_id)->toBeNull()
        ->and($activity->causer_id)->toBe($user->id);
});

it('logs a failed login with the attempted email but never the password', function () {
    event(new Failed('web', null, ['email' => 'attacker@example.com', 'password' => 'secret-guess']));

    $activity = TenantContext::cross(fn (): ?ActivityLog => ActivityLog::query()
        ->where('event', 'failed_login')->latest('id')->first());

    $props = $activity->properties->toArray();

    expect($activity->tenant_id)->toBeNull()
        ->and($props['email'])->toBe('attacker@example.com')
        ->and(json_encode($props))->not->toContain('secret-guess');
});

// --- D-M7-2: role assignment trail ---

it('logs a role grant against the user with the role and client', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    $user->tenants()->attach($tenant);

    TenantContext::run($tenant->id, function () use ($user, $tenant): void {
        Role::findOrCreate(RoleName::Agent->value, 'web');
        $user->assignRole(RoleName::Agent->value);
        Audit::roleGranted($user, RoleName::Agent->value, $tenant->id);
    });

    $activity = TenantContext::run($tenant->id, fn (): ?ActivityLog => ActivityLog::query()
        ->where('log_name', 'rbac')->where('event', 'role_granted')->latest('id')->first());

    expect($activity)->not->toBeNull()
        ->and($activity->tenant_id)->toBe($tenant->id)
        ->and($activity->subject_id)->toBe($user->id)
        ->and($activity->properties['role'])->toBe(RoleName::Agent->value);
});

it('logs a global role grant as ownerless even when a stale tenant GUC is set', function () {
    // The CreateUser shape: a pinned web posture, then forget() — the app
    // context is cleared but the DB GUC may still hold the client. The grant
    // must still land as an ownerless row (and not be rejected by RLS).
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();

    TenantContext::applyWebRequest($tenant->id, crossTenant: false); // GUC = tenant
    TenantContext::forget();                                          // app context cleared

    Audit::roleGranted($user, RoleName::SuperAdmin->value, null);

    $activity = TenantContext::cross(fn (): ?ActivityLog => ActivityLog::query()
        ->where('event', 'role_granted')->latest('id')->first());

    expect($activity)->not->toBeNull()
        ->and($activity->tenant_id)->toBeNull();
});
