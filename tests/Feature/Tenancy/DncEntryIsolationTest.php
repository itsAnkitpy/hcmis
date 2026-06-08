<?php

use App\Models\DncEntry;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::forget();
});

// The three-layer tenant wall (app scope + default-deny + RLS) is proven for
// DncEntry by the shared `tenantModels` dataset in OperationalModelIsolationTest.
// This file covers the M6-specific data rules.

// --- D-M6-4: one number per client, once; same number across clients allowed ---

it('rejects a duplicate number within the same client at the database', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function () {
        DncEntry::factory()->create(['phone' => '9876543210']);
        DncEntry::factory()->create(['phone' => '9876543210']); // same client, same number
    });
})->throws(QueryException::class);

it('allows the same number on two different clients', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    $entryA = TenantContext::run($a->id, fn () => DncEntry::factory()->create(['phone' => '9876543210']));
    $entryB = TenantContext::run($b->id, fn () => DncEntry::factory()->create(['phone' => '9876543210']));

    expect($entryA->tenant_id)->toBe($a->id)
        ->and($entryB->tenant_id)->toBe($b->id)
        ->and($entryA->phone)->toBe($entryB->phone);
});

// --- D-M6-1/-2: the national register is reference data, outside the wall ---

it('keeps the national DNC register outside the tenant wall (reference data)', function () {
    TenantContext::forget(); // no client context at all

    DB::table('national_dnc_entries')->insert([
        'phone' => '1407110000',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Readable with no tenant context — it is not tenant-scoped and has no RLS.
    expect(DB::table('national_dnc_entries')->where('phone', '1407110000')->exists())->toBeTrue();
});

// --- D-M6-5: phone normalized on save (single source of truth, PhoneNumber) ---

it('normalizes the phone on save', function () {
    $tenant = Tenant::factory()->create();

    $entry = TenantContext::run(
        $tenant->id,
        fn () => DncEntry::factory()->create(['phone' => ' (98765) 43-210 ']),
    );

    expect($entry->phone)->toBe('9876543210');
});

// --- D-M6-8: derived Active / Expired label from expires_at (not enforced) ---

it('labels an entry Active or Expired from expires_at', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function () {
        expect(DncEntry::factory()->create()->status)->toBe('Active')                  // no expiry
            ->and(DncEntry::factory()->expiringLater()->create()->status)->toBe('Active')  // future
            ->and(DncEntry::factory()->expired()->create()->status)->toBe('Expired');      // past
    });
});
