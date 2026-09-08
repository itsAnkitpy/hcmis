<?php

use App\Enums\RoleName;
use App\Filament\Resources\Tenants\Pages\EditTenant;
use App\Filament\Resources\Tenants\RelationManagers\UsersRelationManager;
use App\Models\ActivityLog;
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

it('gives a new agent their own phone when they are attached from the client page', function () {
    createAsteriskPhoneTables();

    $existing = User::factory()->create();

    Livewire::test(UsersRelationManager::class, [
        'ownerRecord' => $this->tenant,
        'pageClass' => EditTenant::class,
    ])
        ->callTableAction('attach', data: [
            'recordId' => (string) $existing->getKey(),
            'role_name' => RoleName::Agent->value,
        ])
        ->assertHasNoTableActionErrors();

    $extension = $existing->fresh()->sip_extension;

    expect($extension)->toBe('1100')
        ->and(DB::table('asterisk.ps_endpoints')->where('id', $extension)->exists())->toBeTrue()
        ->and(DB::table('asterisk.ps_auths')->where('id', 'auth'.$extension)->exists())->toBeTrue()
        ->and(DB::table('asterisk.ps_aors')->where('id', $extension)->exists())->toBeTrue();
});

it('gives a phone when a role is changed to agent from the client page', function () {
    createAsteriskPhoneTables();

    $user = User::factory()->create();
    $this->tenant->users()->attach($user);
    TenantContext::run($this->tenant->getKey(), fn () => $user->assignRole(RoleName::Qc->value));

    Livewire::test(UsersRelationManager::class, [
        'ownerRecord' => $this->tenant,
        'pageClass' => EditTenant::class,
    ])
        ->callTableAction('change_role', $user, data: [
            'role_name' => RoleName::Agent->value,
        ])
        ->assertHasNoTableActionErrors();

    expect($user->fresh()->sip_extension)->toBe('1100')
        ->and(DB::table('asterisk.ps_endpoints')->count())->toBe(1);
});

it('gives a phone to an agent created inline on the client page', function () {
    createAsteriskPhoneTables();

    Livewire::test(UsersRelationManager::class, [
        'ownerRecord' => $this->tenant,
        'pageClass' => EditTenant::class,
    ])
        ->callTableAction('add_new', data: [
            'name' => 'Neha Verma',
            'email' => 'neha@example.test',
            'role_name' => RoleName::Agent->value,
        ])
        ->assertHasNoTableActionErrors();

    $user = User::query()->where('email', 'neha@example.test')->firstOrFail();

    expect($user->sip_extension)->toBe('1100')
        ->and(DB::table('asterisk.ps_endpoints')->where('id', '1100')->exists())->toBeTrue();
});

it('gives no phone to someone created inline on the client page in any other role', function () {
    createAsteriskPhoneTables();

    Livewire::test(UsersRelationManager::class, [
        'ownerRecord' => $this->tenant,
        'pageClass' => EditTenant::class,
    ])
        ->callTableAction('add_new', data: [
            'name' => 'Neha Verma',
            'email' => 'neha@example.test',
            'role_name' => RoleName::Qc->value,
        ])
        ->assertHasNoTableActionErrors();

    $user = User::query()->where('email', 'neha@example.test')->firstOrFail();

    expect($user->sip_extension)->toBeNull()
        ->and(DB::table('asterisk.ps_endpoints')->count())->toBe(0);
});

it('records a role_granted line for a user created inline on the client page', function () {
    Livewire::test(UsersRelationManager::class, [
        'ownerRecord' => $this->tenant,
        'pageClass' => EditTenant::class,
    ])
        ->callTableAction('add_new', data: [
            'name' => 'Neha Verma',
            'email' => 'neha@example.test',
            'role_name' => RoleName::TeamLeader->value,
        ])
        ->assertHasNoTableActionErrors();

    $user = User::query()->where('email', 'neha@example.test')->firstOrFail();

    $activity = TenantContext::run($this->tenant->getKey(), fn (): ?ActivityLog => ActivityLog::query()
        ->where('log_name', 'rbac')
        ->where('event', 'role_granted')
        ->where('subject_id', $user->getKey())
        ->latest('id')
        ->first());

    expect($activity)->not->toBeNull()
        ->and($activity->properties['role'])->toBe(RoleName::TeamLeader->value)
        ->and($activity->properties['team_id'])->toBe($this->tenant->getKey());
});
