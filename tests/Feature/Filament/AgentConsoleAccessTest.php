<?php

use App\Enums\RoleName;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

afterEach(function () {
    // The panel's web request stamps the tenant marker with plain SET, which
    // survives the RefreshDatabase rollback; clear it so the posture doesn't
    // leak into the next test on this connection (same guard as the M4 tests).
    TenantContext::resetWebRequest();
    TenantContext::forget();
});

/** An HC user holding a global role at the reserved global team (0). */
function hcStaffUser(string $role): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    Role::findOrCreate($role, 'web'); // no context -> global team (0)
    $user->assignRole($role);

    return $user;
}

it('lets an agent open the Agent Console — their first surface (D1)', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $this->actingAs($agent)->get('/admin/agent-console')->assertSuccessful();
});

it('lets global staff open the Agent Console (demo/testing)', function () {
    // super_admin is the global staff that reaches the panel: canAccessPanel
    // admits super_admin or tenant members, so a bare ops_manager (no client)
    // is bounced at the middleware before canAccess() — an M2 gate, not B4's.
    $admin = hcStaffUser(RoleName::SuperAdmin->value);

    $this->actingAs($admin)->get('/admin/agent-console')->assertSuccessful();
});

it('forbids the Agent Console for non-agent client roles', function (string $role) {
    $tenant = Tenant::factory()->create();
    $user = clientUserWithRole($tenant, $role);

    $this->actingAs($user)->get('/admin/agent-console')->assertForbidden();
})->with([
    'team leader' => [RoleName::TeamLeader->value],
    'qc' => [RoleName::Qc->value],
    'client user' => [RoleName::ClientUser->value],
]);
