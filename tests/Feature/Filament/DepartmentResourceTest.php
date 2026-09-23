<?php

use App\Enums\MenuAction;
use App\Enums\RoleName;
use App\Filament\Resources\Departments\DepartmentResource;
use App\Filament\Resources\Departments\Pages\CreateDepartment;
use App\Filament\Resources\Departments\Pages\EditDepartment;
use App\Filament\Resources\Departments\Pages\ListDepartments;
use App\Models\Call;
use App\Models\Department;
use App\Models\Menu;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Filament\Actions\DeleteAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * inbound-audio.md slice 7 (D2, D9) — the departments screen. Head office owns a
 * department; a team leader changes only who is in it.
 *
 * Mounting the list page is deliberate: S170 bug 1 was strictAuthorization() crashing
 * the menus list on a missing policy method, which no create/edit test caught.
 */
uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::resetWebRequest();
    TenantContext::forget();
});

/** Head office in its only posture: no client selected, cross-client on (see MenuResourceTest). */
function departmentHeadOffice(): void
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    Role::findOrCreate(RoleName::HcAdmin->value, 'web');
    $user->assignRole(RoleName::HcAdmin->value);

    test()->actingAs($user->fresh());
    TenantContext::applyWebRequest(null, true);
}

function departmentTeamLeader(Tenant $tenant): void
{
    test()->actingAs(clientUserWithRole($tenant, RoleName::TeamLeader->value)->fresh());
    TenantContext::applyWebRequest($tenant->id, false);
}

/** Someone at this client the router can ring: a member of the client, with a phone. */
function departmentAgent(Tenant $tenant, string $extension): User
{
    $agent = User::factory()->create(['sip_extension' => $extension]);
    $agent->tenants()->attach($tenant);

    return $agent;
}

it('opens the list page for head office and for a team leader', function () {
    $tenant = Tenant::factory()->create();
    $department = TenantContext::run($tenant->id, fn (): Department => Department::factory()->create(['name' => 'Hindi']));

    departmentHeadOffice();
    Livewire::test(ListDepartments::class)->assertOk()->assertCanSeeTableRecords([$department]);

    departmentTeamLeader($tenant);
    Livewire::test(ListDepartments::class)->assertOk()->assertCanSeeTableRecords([$department]);
});

it('lets head office create a department for a client, with its agents', function () {
    $tenant = Tenant::factory()->create();
    $agent = departmentAgent($tenant, '2001');
    departmentHeadOffice();

    Livewire::test(CreateDepartment::class)
        ->fillForm(['tenant_id' => $tenant->id, 'name' => 'Hindi', 'is_active' => true, 'members' => [$agent->id]])
        ->call('create')
        ->assertHasNoFormErrors();

    TenantContext::run($tenant->id, function () use ($agent) {
        $department = Department::query()->sole();

        expect($department->name)->toBe('Hindi')
            ->and($department->members->pluck('id')->all())->toBe([$agent->id]);
    });
});

it('refuses a team leader create and delete', function () {
    $tenant = Tenant::factory()->create();
    $department = TenantContext::run($tenant->id, fn (): Department => Department::factory()->create());
    departmentTeamLeader($tenant);

    expect(DepartmentResource::canCreate())->toBeFalse()
        ->and(DepartmentResource::canDelete($department))->toBeFalse();

    Livewire::test(EditDepartment::class, ['record' => $department->getKey()])
        ->assertActionHidden(DeleteAction::class);
});

it('saves a team leader\'s member change but not their name or switch change', function () {
    $tenant = Tenant::factory()->create();
    $agent = departmentAgent($tenant, '2002');
    $department = TenantContext::run($tenant->id, fn (): Department => Department::factory()->create(['name' => 'Hindi']));
    departmentTeamLeader($tenant);

    // What matters is the SAVED ROW, not how the fields look.
    Livewire::test(EditDepartment::class, ['record' => $department->getKey()])
        ->assertFormFieldIsDisabled('name')
        ->assertFormFieldIsDisabled('is_active')
        ->fillForm(['name' => 'Renamed', 'is_active' => false, 'members' => [$agent->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    TenantContext::run($tenant->id, function () use ($department, $agent) {
        $department->refresh();

        expect($department->name)->toBe('Hindi')
            ->and($department->is_active)->toBeTrue()
            ->and($department->members->pluck('id')->all())->toBe([$agent->id])
            ->and(DB::table('department_user')->value('tenant_id'))->toBe($department->tenant_id);
    });
});

it('refuses another client\'s agent as a member', function () {
    $tenant = Tenant::factory()->create();
    $foreignAgent = departmentAgent(Tenant::factory()->create(), '2003');
    $department = TenantContext::run($tenant->id, fn (): Department => Department::factory()->create());
    departmentHeadOffice();

    Livewire::test(EditDepartment::class, ['record' => $department->getKey()])
        ->fillForm(['members' => [$foreignAgent->id]])
        ->call('save')
        ->assertHasFormErrors(['members']);

    TenantContext::run($tenant->id, fn () => expect($department->fresh()->members)->toBeEmpty());
});

it('hides delete for a department a call points at, and deletes an unused one', function () {
    $tenant = Tenant::factory()->create();
    [$used, $unused] = TenantContext::run($tenant->id, function (): array {
        $used = Department::factory()->create();
        Call::factory()->create()->forceFill(['department_id' => $used->id])->save();

        return [$used, Department::factory()->create()];
    });
    departmentHeadOffice();

    Livewire::test(ListDepartments::class)
        ->assertTableActionHidden(DeleteAction::class, $used)
        ->callTableAction(DeleteAction::class, $unused);

    TenantContext::run($tenant->id, fn () => expect(Department::query()->pluck('id')->all())->toBe([$used->id]));
});

it('refuses to switch off a department a menu key rings, and names the menu (D9)', function () {
    $tenant = Tenant::factory()->create();
    $department = TenantContext::run($tenant->id, function (): Department {
        $department = Department::factory()->create(['name' => 'Hindi']);
        Menu::factory()->withGreeting()->create([
            'name' => 'Main menu',
            'options' => [['key' => '2', 'label' => 'Hindi', 'action' => MenuAction::RingDepartment->value, 'department_id' => $department->id]],
        ]);

        return $department;
    });
    departmentHeadOffice();

    Livewire::test(EditDepartment::class, ['record' => $department->getKey()])
        ->assertActionHidden(DeleteAction::class)
        ->fillForm(['is_active' => false])
        ->call('save')
        ->assertHasFormErrors(['is_active'])
        ->assertSee('A key on Main menu rings this department.');

    TenantContext::run($tenant->id, fn () => expect($department->fresh()->is_active)->toBeTrue());
});
