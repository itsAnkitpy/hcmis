<?php

use App\Models\ActivityLog;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextMissingException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::forget();
});

// --- D-M7-1: tenant stamping (subject tenant -> context -> null) ---

it('stamps the active tenant context onto a row', function () {
    $tenant = Tenant::factory()->create();

    $row = TenantContext::run($tenant->id, fn (): ActivityLog => ActivityLog::factory()->create());

    expect($row->tenant_id)->toBe($tenant->id);
});

it('leaves a row ownerless when there is no tenant context', function () {
    $row = ActivityLog::factory()->auth()->create(); // a login: no context

    expect($row->tenant_id)->toBeNull();
});

it('attributes a tenant-owned subject to its own client even in the all-clients posture', function () {
    $tenant = Tenant::factory()->create();
    $lead = TenantContext::run(
        $tenant->id,
        fn (): Lead => Lead::factory()->forCampaign(Campaign::factory()->create())->create(),
    );

    // A super admin editing the lead while pinned to no single client (bypass on):
    // the row must still be stamped to the lead's own client, not left ownerless.
    $row = TenantContext::cross(function () use ($lead): ActivityLog {
        $activity = ActivityLog::factory()->make();
        $activity->subject()->associate($lead);
        $activity->save();

        return $activity;
    });

    expect($row->tenant_id)->toBe($tenant->id);
});

// --- read scope: same wall as the operational models, at the Eloquent layer ---

it('scopes reads to the active client and hides ownerless rows', function () {
    $a = Tenant::factory()->create();

    ActivityLog::factory()->auth()->create();                                  // ownerless
    TenantContext::run($a->id, fn () => ActivityLog::factory()->create());      // A-owned

    $seenByA = TenantContext::run($a->id, fn (): int => ActivityLog::count());

    expect($seenByA)->toBe(1); // own row only — ownerless excluded
});

it('shows every row including ownerless ones on the cross-tenant path', function () {
    $a = Tenant::factory()->create();

    ActivityLog::factory()->auth()->create(); // ownerless
    TenantContext::run($a->id, fn () => ActivityLog::factory()->create()); // A-owned

    // Counted by ownership so the assertion stays robust to incidental
    // auto-logged rows (e.g. the tenant-created event).
    $counts = TenantContext::cross(fn (): array => [
        'ownerless' => ActivityLog::whereNull('tenant_id')->count(),
        'tenantA' => ActivityLog::where('tenant_id', $a->id)->count(),
    ]);

    expect($counts['ownerless'])->toBeGreaterThanOrEqual(1)
        ->and($counts['tenantA'])->toBe(1);
});

it('default-denies an Eloquent read when no tenant context is set', function () {
    ActivityLog::count();
})->throws(TenantContextMissingException::class);

// --- D-M7-2: automatic capture + before→after diff ---

it('captures an updated event with a before/after diff when a lead changes', function () {
    $tenant = Tenant::factory()->create();

    $activity = TenantContext::run($tenant->id, function (): ?ActivityLog {
        $lead = Lead::factory()->forCampaign(Campaign::factory()->create())->create(['name' => 'Old Name']);
        $lead->update(['name' => 'New Name']);

        return ActivityLog::query()
            ->where('subject_id', $lead->id)
            ->where('event', 'updated')
            ->latest('id')
            ->first();
    });

    // v5 stores the before→after diff in the attribute_changes column.
    $changes = $activity->attribute_changes->toArray();

    expect($activity->log_name)->toBe('lead')
        ->and($activity->tenant_id)->toBe($tenant->id)
        ->and($changes['attributes']['name'])->toBe('New Name')
        ->and($changes['old']['name'])->toBe('Old Name');
});

// --- D-M7-2 security must-do: the password value is never recorded ---

it('records a user name change without ever logging the password', function () {
    $user = User::factory()->create();

    $activity = TenantContext::cross(function () use ($user): ?ActivityLog {
        $user->update(['name' => 'Renamed', 'password' => Hash::make('super-secret-value')]);

        return ActivityLog::query()
            ->where('subject_id', $user->id)
            ->where('event', 'updated')
            ->latest('id')
            ->first();
    });

    $changes = $activity->attribute_changes->toArray();

    expect($changes['attributes'])->toHaveKey('name')
        ->and($changes['attributes'])->not->toHaveKey('password')
        ->and(json_encode($changes))->not->toContain('super-secret-value');
});

it('writes no activity when only an unlogged column changes', function () {
    $user = User::factory()->create();

    TenantContext::cross(function () use ($user): void {
        $before = ActivityLog::count();
        $user->update(['password' => Hash::make('x')]); // password is not on the allowlist

        expect(ActivityLog::count())->toBe($before);
    });
});
