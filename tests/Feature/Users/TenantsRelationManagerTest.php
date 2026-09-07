<?php

use App\Enums\RoleName;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\RelationManagers\TenantsRelationManager;
use App\Models\Tenant;
use App\Models\User;
use App\Telephony\AgentPhoneWriter;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
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

/** The relation manager under test, mounted the same way every time. */
function clientsPanel(User $user): Testable
{
    return Livewire::test(TenantsRelationManager::class, [
        'ownerRecord' => $user,
        'pageClass' => EditUser::class,
    ]);
}

/** Attach the user to a client in the given role, through the screen. */
function attachAs(User $user, Tenant $tenant, RoleName $role): void
{
    clientsPanel($user)
        ->callTableAction('attach', data: [
            'recordId' => (string) $tenant->getKey(),
            'role_name' => $role->value,
        ])
        ->assertHasNoTableActionErrors();
}

// --- PP-8: becoming an agent gets you a working phone ---

it('gives a new agent their own phone when they are attached to a client', function () {
    createAsteriskPhoneTables();

    attachAs($this->user, Tenant::factory()->create(), RoleName::Agent);

    $extension = $this->user->fresh()->sip_extension;

    expect($extension)->toBe('1100')
        ->and(DB::table('asterisk.ps_endpoints')->where('id', $extension)->exists())->toBeTrue()
        ->and(DB::table('asterisk.ps_auths')->where('id', 'auth'.$extension)->value('password'))->not->toBeEmpty();
});

it('gives no phone to someone attached in any other role', function () {
    createAsteriskPhoneTables();

    attachAs($this->user, Tenant::factory()->create(), RoleName::Qc);

    expect($this->user->fresh()->sip_extension)->toBeNull()
        ->and(DB::table('asterisk.ps_endpoints')->count())->toBe(0);
});

// --- PP-9: a role correction is not a departure, and never churns a phone ---

it('keeps the same phone when an agent is moved to another role and back', function () {
    createAsteriskPhoneTables();
    $tenant = Tenant::factory()->create();

    attachAs($this->user, $tenant, RoleName::Agent);
    $original = $this->user->fresh()->sip_extension;

    clientsPanel($this->user)
        ->callTableAction('change_role', $tenant, data: ['role_name' => RoleName::Qc->value])
        ->assertHasNoTableActionErrors();

    expect($this->user->fresh()->sip_extension)->toBe($original);

    clientsPanel($this->user)
        ->callTableAction('change_role', $tenant, data: ['role_name' => RoleName::Agent->value])
        ->assertHasNoTableActionErrors();

    expect($this->user->fresh()->sip_extension)->toBe($original)
        ->and(DB::table('asterisk.ps_endpoints')->count())->toBe(1);
});

it('leaves the phone standing when the user is removed from the client', function () {
    createAsteriskPhoneTables();
    $tenant = Tenant::factory()->create();

    attachAs($this->user, $tenant, RoleName::Agent);
    $extension = $this->user->fresh()->sip_extension;

    clientsPanel($this->user)
        ->callTableAction('detach', $tenant)
        ->assertHasNoTableActionErrors();

    expect($this->user->fresh()->sip_extension)->toBe($extension)
        ->and(DB::table('asterisk.ps_endpoints')->where('id', $extension)->exists())->toBeTrue()
        ->and(DB::table('asterisk.ps_auths')->where('id', 'auth'.$extension)->value('password'))->not->toBeEmpty();
});

// --- RS-3: a number taken in the same moment is said in words, not in SQL ---

it('keeps the role and explains itself when the number was taken in the same moment', function () {
    createAsteriskPhoneTables();
    $tenant = Tenant::factory()->create();

    app()->bind(AgentPhoneWriter::class, fn () => new class extends AgentPhoneWriter
    {
        public function provisionFor(User $user): string
        {
            throw new QueryException('pgsql', 'insert into "users"', [],
                new PDOException('duplicate key value violates unique constraint', 23505));
        }
    });

    clientsPanel($this->user)
        ->callTableAction('attach', data: [
            'recordId' => (string) $tenant->getKey(),
            'role_name' => RoleName::Agent->value,
        ])
        ->assertHasNoTableActionErrors()
        ->assertNotified('Role saved, but the phone was not created');

    TenantContext::run($tenant->getKey(), function () {
        expect($this->user->fresh()->hasRole(RoleName::Agent->value))->toBeTrue();
    });

    expect($this->user->fresh()->sip_extension)->toBeNull();
});

it('never gives a second number to someone who was given one after this page opened', function () {
    createAsteriskPhoneTables();
    $tenant = Tenant::factory()->create();
    $panel = clientsPanel($this->user);

    // Another admin, or this admin in another tab, allocates their phone meanwhile.
    $this->user->forceFill(['sip_extension' => '1100'])->save();

    $panel->callTableAction('attach', data: [
        'recordId' => (string) $tenant->getKey(),
        'role_name' => RoleName::Agent->value,
    ])->assertHasNoTableActionErrors();

    expect($this->user->fresh()->sip_extension)->toBe('1100')
        ->and(DB::table('asterisk.ps_endpoints')->count())->toBe(0);
});
