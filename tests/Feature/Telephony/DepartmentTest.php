<?php

use App\Models\Call;
use App\Models\CallHandoff;
use App\Models\Department;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::forget();
});

// --- switch off, never delete (D9, the BK-1 rule) ---

it('refuses to delete a department a call points at', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function () {
        $department = Department::factory()->create();
        Call::factory()->create()->forceFill(['department_id' => $department->id])->save();

        expect(fn () => $department->delete())->toThrow(QueryException::class, 'violates RESTRICT setting of foreign key constraint');
    });
});

it('refuses to delete a department a ring-time note points at', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function () {
        $department = Department::factory()->create();
        CallHandoff::factory()->create()->forceFill(['department_id' => $department->id])->save();

        expect(fn () => $department->delete())->toThrow(QueryException::class, 'violates RESTRICT setting of foreign key constraint');
    });
});

it('deletes a department no call ever used, members and all', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function () {
        $department = Department::factory()->create();
        $department->members()->attach(User::factory()->create());

        $department->delete();

        expect(Department::query()->count())->toBe(0)
            ->and(DB::table('department_user')->count())->toBe(0);
    });
});

// --- membership ---

it('stamps a member row with the department\'s own client when head office adds it with no client in context', function () {
    $tenant = Tenant::factory()->create();
    [$department, $agent] = TenantContext::run($tenant->id, fn () => [
        Department::factory()->create(),
        User::factory()->create(),
    ]);

    TenantContext::cross(function () use ($department, $agent) {
        $department->members()->attach($agent);
    });

    TenantContext::run($tenant->id, function () use ($department, $agent) {
        expect($department->fresh()->members->pluck('id')->all())->toBe([$agent->id])
            ->and(DB::table('department_user')->value('tenant_id'))->toBe($department->tenant_id);
    });
});

it('lets one agent sit in several departments, a bilingual agent in Sales and Hindi (D1)', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function () {
        $agent = User::factory()->create();
        [$sales, $hindi] = Department::factory()->count(2)->create()->all();

        $sales->members()->attach($agent);
        $hindi->members()->attach($agent);

        expect($sales->members->pluck('id')->all())->toBe([$agent->id])
            ->and($hindi->members->pluck('id')->all())->toBe([$agent->id]);
    });
});

// --- the client's wait setting (D3) ---

it('waits the config default of 60 seconds unless the client set its own', function () {
    $tenant = Tenant::factory()->create();

    expect($tenant->departmentWaitSeconds())->toBe(60);

    $tenant->update(['department_wait_seconds' => 45]);

    expect($tenant->fresh()->departmentWaitSeconds())->toBe(45);
});
