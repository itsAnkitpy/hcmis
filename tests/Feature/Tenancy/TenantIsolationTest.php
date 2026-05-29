<?php

use App\Models\Tenant;
use App\Tenancy\Rls;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextMissingException;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\ImportLeadJob;
use Tests\Fixtures\TenantOwnedModel;

uses(RefreshDatabase::class);

beforeEach(function () {
    Schema::create('tenant_owned_models', function (Blueprint $table) {
        $table->id();
        $table->foreignId('tenant_id')->constrained();
        $table->string('label')->nullable();
    });

    Rls::enable('tenant_owned_models');

    $this->tenantA = Tenant::factory()->create();
    $this->tenantB = Tenant::factory()->create();

    TenantContext::run($this->tenantA->id, fn () => TenantOwnedModel::create(['label' => 'a-row']));
    TenantContext::run($this->tenantB->id, fn () => TenantOwnedModel::create(['label' => 'b-row']));
});

afterEach(function () {
    TenantContext::forget();
});

// --- Layer 2: app-layer Eloquent scoping ---

it('scopes Eloquent reads to the active tenant', function () {
    $labels = TenantContext::run($this->tenantA->id, fn () => TenantOwnedModel::pluck('label')->all());

    expect($labels)->toBe(['a-row']);
});

// --- Layer 3: Postgres RLS (proven by bypassing the Eloquent scope) ---

it('blocks a raw cross-tenant SELECT at the database via RLS', function () {
    $rows = TenantContext::run($this->tenantA->id, fn () => DB::select('select label from tenant_owned_models'));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->label)->toBe('a-row');
});

it('returns zero rows for a raw query when no tenant is set (DB default-deny)', function () {
    // No TenantContext::run wrapper, so no tenant GUC -> RLS denies everything.
    $rows = DB::select('select * from tenant_owned_models');

    expect($rows)->toHaveCount(0);
});

it('rejects a raw cross-tenant INSERT via the RLS WITH CHECK clause', function () {
    $otherTenantId = $this->tenantB->id;

    expect(fn () => TenantContext::run($this->tenantA->id, fn () => DB::insert(
        'insert into tenant_owned_models (tenant_id, label) values (?, ?)',
        [$otherTenantId, 'leak'],
    )))->toThrow(QueryException::class);
});

// --- Audited cross-tenant path ---

it('sees every tenant through the audited cross() path (Eloquent + raw)', function () {
    $eloquentCount = TenantContext::cross(fn () => TenantOwnedModel::count());
    $rawCount = TenantContext::cross(fn () => (int) DB::select('select count(*) as c from tenant_owned_models')[0]->c);

    expect($eloquentCount)->toBe(2)
        ->and($rawCount)->toBe(2);
});

// --- D-002 control #1: no-auth entry paths (queued jobs) ---

it('scopes a queued job that runs with no authenticated user', function () {
    ImportLeadJob::dispatchSync($this->tenantA->id, 'from-job');

    $row = TenantContext::cross(fn () => TenantOwnedModel::firstWhere('label', 'from-job'));

    expect($row)->not->toBeNull()
        ->and($row->tenant_id)->toBe($this->tenantA->id);
});

it('default-denies a no-auth path that forgets to set tenant context', function () {
    // A job/handler body that touches tenant data without TenantContext::run.
    TenantOwnedModel::create(['label' => 'orphan']);
})->throws(TenantContextMissingException::class);
