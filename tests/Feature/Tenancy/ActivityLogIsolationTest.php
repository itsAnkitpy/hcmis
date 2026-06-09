<?php

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::forget();
});

/**
 * Insert a raw activity_log row, bypassing the Eloquent model so these tests
 * prove the DB-level variant RLS + append-only revoke on their own (the M7
 * catastrophic seam, D-M7-1/-5) — independent of any model scope or hook.
 */
function insertActivityRow(?int $tenantId, string $description = 'event'): void
{
    DB::table('activity_log')->insert([
        'log_name' => 'test',
        'description' => $description,
        'tenant_id' => $tenantId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// --- D-M7-1: ownerless inserts pass the widened WITH CHECK ---

it('allows an ownerless insert with no tenant context', function () {
    TenantContext::forget(); // a login: no client picked yet

    insertActivityRow(null, 'user logged in');

    // Visible on the bypass path, proving the row actually landed.
    $count = TenantContext::cross(fn (): int => DB::table('activity_log')->count());

    expect($count)->toBe(1);
});

// --- D-M7-1: the read predicate is the standard tenant wall ---

it('hides an ownerless row from a pinned client', function () {
    insertActivityRow(null, 'global login');

    $tenant = Tenant::factory()->create();
    $seen = TenantContext::run($tenant->id, fn (): int => DB::table('activity_log')->count());

    expect($seen)->toBe(0);
});

it('hides one client\'s rows from another client', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    TenantContext::run($a->id, fn () => insertActivityRow($a->id, 'A edited a lead'));

    $seenByB = TenantContext::run($b->id, fn (): int => DB::table('activity_log')->count());

    expect($seenByB)->toBe(0);
});

it('shows both ownerless and tenant rows to HC staff on the bypass path', function () {
    $a = Tenant::factory()->create();

    insertActivityRow(null, 'global login');
    TenantContext::run($a->id, fn () => insertActivityRow($a->id, 'A edited a lead'));

    // Counted by ownership so the assertion stays robust to incidental
    // auto-logged rows (the tenant-created event is itself ownerless).
    $counts = TenantContext::cross(fn (): array => [
        'ownerless' => DB::table('activity_log')->whereNull('tenant_id')->count(),
        'tenantA' => DB::table('activity_log')->where('tenant_id', $a->id)->count(),
    ]);

    expect($counts['ownerless'])->toBeGreaterThanOrEqual(1)
        ->and($counts['tenantA'])->toBe(1);
});

// --- D-M7-1: a pinned client still cannot stamp a row to another tenant ---

it('rejects an insert stamped to another tenant from a pinned client', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    expect(fn () => TenantContext::run($a->id, fn () => insertActivityRow($b->id)))
        ->toThrow(QueryException::class);
});

// --- D-M7-5: append-only at the DB layer (REVOKE UPDATE, DELETE) ---
// Run on the bypass path so RLS does not hide the row — isolating the revoke
// as the thing that denies the write.

it('denies UPDATE on a logged row at the database', function () {
    insertActivityRow(null, 'original');

    expect(fn () => TenantContext::cross(
        fn () => DB::table('activity_log')->update(['description' => 'tampered']),
    ))->toThrow(QueryException::class);
});

it('denies DELETE on a logged row at the database', function () {
    insertActivityRow(null, 'keep me');

    expect(fn () => TenantContext::cross(
        fn () => DB::table('activity_log')->delete(),
    ))->toThrow(QueryException::class);
});
