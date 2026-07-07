<?php

use App\Actions\SeedBreakCategories;
use App\Enums\RoleName;
use App\Models\ActivityLog;
use App\Models\BreakCategory;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::resetWebRequest();
    TenantContext::forget();
});

// --- seeding on tenant creation (BK-1) ---

it('seeds the six starter break types for every new tenant', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function () use ($tenant) {
        /** @var array<int, array{code: string, label: string}> $expected */
        $expected = config('hcims.break_category_defaults');
        $seeded = BreakCategory::query()->orderBy('sort_order')->get();

        expect($seeded)->toHaveCount(count($expected));

        foreach ($expected as $index => $row) {
            expect($seeded[$index]->code)->toBe($row['code'])
                ->and($seeded[$index]->label)->toBe($row['label'])
                ->and($seeded[$index]->time_limit_minutes)->toBeNull() // no limits pre-filled — ops question 2
                ->and($seeded[$index]->is_active)->toBeTrue()
                ->and($seeded[$index]->sort_order)->toBe($index)
                ->and($seeded[$index]->tenant_id)->toBe($tenant->id);
        }
    });
});

it('is a no-op when the tenant already has categories (seed-once)', function () {
    $tenant = Tenant::factory()->create(); // observer already seeded here

    SeedBreakCategories::run($tenant);     // the backfill path re-runs safely

    TenantContext::run($tenant->id, function () {
        expect(BreakCategory::query()->count())
            ->toBe(count(config('hcims.break_category_defaults')));
    });
});

it('does not leak one client\'s categories into another', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    TenantContext::run($tenantB->id, function () use ($tenantA) {
        expect(BreakCategory::query()->where('tenant_id', $tenantA->id)->count())->toBe(0)
            ->and(BreakCategory::query()->count())
            ->toBe(count(config('hcims.break_category_defaults')));
    });
});

// --- the gate (BK-1: admins manage, agents never see it, nobody deletes) ---

it('keeps team leader write access but denies delete to every role', function () {
    $tenant = Tenant::factory()->create();
    $opsManager = reportsHcUser(RoleName::OpsManager->value);
    $teamLeader = clientUserWithRole($tenant, RoleName::TeamLeader->value);

    // Global staff keep full manage rights — except delete (deactivate instead).
    expect($opsManager->can('viewAny', BreakCategory::class))->toBeTrue()
        ->and($opsManager->can('create', BreakCategory::class))->toBeTrue()
        ->and($opsManager->can('update', BreakCategory::class))->toBeTrue()
        ->and($opsManager->can('delete', BreakCategory::class))->toBeFalse()
        ->and($opsManager->can('deleteAny', BreakCategory::class))->toBeFalse();

    TenantContext::run($tenant->id, function () use ($teamLeader) {
        $teamLeader = $teamLeader->fresh();
        expect($teamLeader->can('update', BreakCategory::class))->toBeTrue()
            ->and($teamLeader->can('delete', BreakCategory::class))->toBeFalse();
    });
});

it('lets a team leader open the break categories screen', function () {
    $tenant = Tenant::factory()->create();
    $tl = clientUserWithRole($tenant, RoleName::TeamLeader->value);

    $this->actingAs($tl)->get('/admin/break-categories')->assertSuccessful();
});

it('forbids the break categories screen for an agent', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $this->actingAs($agent)->get('/admin/break-categories')->assertForbidden();
});

// --- every edit is audited (BK-1 — the dispositions pattern) ---

it('writes no audit rows for the seeded defaults', function () {
    // Seeding is platform provisioning, not an admin action — same posture as
    // role provisioning. Only manual creates and edits land in the audit log.
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function () {
        expect(ActivityLog::query()->where('log_name', 'break_category')->count())->toBe(0);
    });
});

it('audits a category edit with old and new values', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function () {
        $category = BreakCategory::query()->where('code', 'LUNCH_BREAK')->firstOrFail();

        $category->update(['time_limit_minutes' => 30]);

        $activity = ActivityLog::query()
            ->where('log_name', 'break_category')
            ->where('subject_id', $category->id)
            ->where('event', 'updated')
            ->latest('id')
            ->first();

        // v5 stores the before→after diff in the attribute_changes column.
        $changes = $activity->attribute_changes->toArray();

        expect($activity)->not->toBeNull()
            ->and($changes['attributes']['time_limit_minutes'])->toBe(30)
            ->and($changes['old']['time_limit_minutes'])->toBeNull();
    });
});
