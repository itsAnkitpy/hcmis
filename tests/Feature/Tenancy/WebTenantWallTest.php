<?php

use App\Enums\TenantStatus;
use App\Http\Middleware\SetCurrentTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\Rls;
use App\Tenancy\TenantContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    // A throwaway RLS-protected tenant-owned table standing in for M4.B's real
    // tables, so M4.A's web wall is proven before any domain table exists.
    Schema::create('web_tenant_rows', function (Blueprint $table) {
        $table->id();
        $table->foreignId('tenant_id')->constrained();
        $table->string('label')->nullable();
    });

    Rls::enable('web_tenant_rows');

    $this->tenantA = Tenant::factory()->create();
    $this->tenantB = Tenant::factory()->create();

    TenantContext::run($this->tenantA->id, fn () => DB::table('web_tenant_rows')->insert([
        'tenant_id' => $this->tenantA->id, 'label' => 'a-row',
    ]));
    TenantContext::run($this->tenantB->id, fn () => DB::table('web_tenant_rows')->insert([
        'tenant_id' => $this->tenantB->id, 'label' => 'b-row',
    ]));
});

afterEach(function () {
    TenantContext::forget();
});

/**
 * Run the real SetCurrentTenant middleware for a faked authenticated panel
 * request, returning whatever the $inside closure (the "panel body") produces.
 * Exercises the actual bridge: operatesGlobally branch, resolveTenantId,
 * applyWebRequest — and the plain-SET marker it stamps.
 */
function runWebBridge(User $user, ?Closure $inside = null): mixed
{
    $request = Request::create('/admin');
    $request->setLaravelSession(app('session.store'));
    $request->setUserResolver(fn () => $user);

    // $next must return a Response; capture the "panel body" result separately.
    $captured = null;
    $response = (new SetCurrentTenant)->handle($request, function () use ($inside, &$captured) {
        $captured = $inside ? $inside() : null;

        return response('ok');
    });

    // Data tests pass $inside and want its result; redirect tests pass none and
    // want the (short-circuited) response.
    return $inside ? $captured : $response;
}

function memberOf(Tenant $tenant): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->tenants()->attach($tenant);

    return $user;
}

function makeOpsManager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    Role::findOrCreate('ops_manager', 'web'); // no context -> global team (0)
    $user->assignRole('ops_manager');

    return $user;
}

// --- the web database wall (RLS over a real web request) ---

it('lets a client-A user read only client A rows via a raw web query', function () {
    $rows = runWebBridge(memberOf($this->tenantA), fn () => DB::select('select label from web_tenant_rows'));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->label)->toBe('a-row');
});

it('lets a client-B user read only client B rows via a raw web query', function () {
    $rows = runWebBridge(memberOf($this->tenantB), fn () => DB::select('select label from web_tenant_rows'));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->label)->toBe('b-row');
});

it('lets a global HC user read every client row via a raw web query', function () {
    $rows = runWebBridge(makeOpsManager(), fn () => DB::select('select label from web_tenant_rows order by label'));

    expect($rows)->toHaveCount(2)
        ->and(collect($rows)->pluck('label')->all())->toBe(['a-row', 'b-row']);
});

it('default-denies (zero rows) when a request has no operable client', function () {
    // The block-screen branch wipes the marker before redirecting; prove the DB
    // is left in the safe default-deny state, not leaking a prior tenant.
    $user = memberOf($this->tenantA);
    $this->tenantA->transitionTo(TenantStatus::Suspended, 'test');

    runWebBridge($user); // returns the redirect; marker is wiped

    expect(DB::select('select * from web_tenant_rows'))->toHaveCount(0);
});

// --- suspended-client block screen (M3 §5.1, D-M4-6) ---

it('redirects a suspended-only client user to the block screen', function () {
    $user = memberOf($this->tenantA);
    $this->tenantA->transitionTo(TenantStatus::Suspended, 'test');

    $response = runWebBridge($user);

    expect($response->isRedirect(route('tenant.suspended')))->toBeTrue();
});

it('bounces a suspended user away from the panel to the block screen', function () {
    $user = memberOf($this->tenantA);
    $this->tenantA->transitionTo(TenantStatus::Suspended, 'test');

    $this->actingAs($user)->get('/admin')->assertRedirect(route('tenant.suspended'));
});

it('renders the block screen with the suspended client name', function () {
    $user = memberOf($this->tenantA);
    $this->tenantA->transitionTo(TenantStatus::Suspended, 'test');

    $this->actingAs($user)->get(route('tenant.suspended'))
        ->assertOk()
        ->assertSee($this->tenantA->name)
        ->assertSee('Account unavailable');
});

// --- topbar client switcher (FR-U03) ---

it('switches a multi-client user to a client they belong to', function () {
    $user = memberOf($this->tenantA);
    $user->tenants()->attach($this->tenantB);

    $this->actingAs($user)
        ->post(route('tenant.switch'), ['tenant' => $this->tenantB->id])
        ->assertRedirect();

    expect(session('current_tenant_id'))->toBe($this->tenantB->id);
});

it('refuses to switch to a client the user does not belong to (default-deny)', function () {
    $user = memberOf($this->tenantA);

    $this->actingAs($user)->post(route('tenant.switch'), ['tenant' => $this->tenantB->id]);

    expect(session('current_tenant_id'))->not->toBe($this->tenantB->id);
});
