<?php

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextMissingException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\TenantOwnedModel;

uses(RefreshDatabase::class);

beforeEach(function () {
    Schema::create('tenant_owned_models', function (Blueprint $table) {
        $table->id();
        $table->foreignId('tenant_id')->constrained();
        $table->string('label')->nullable();
    });
});

afterEach(function () {
    TenantContext::forget();
});

it('auto-stamps tenant_id from the active context on create', function () {
    $tenant = Tenant::factory()->create();

    $row = TenantContext::run($tenant->id, fn () => TenantOwnedModel::create(['label' => 'x']));

    expect($row->tenant_id)->toBe($tenant->id);
});

it('accepts a Tenant model (not just an id) for run()', function () {
    $tenant = Tenant::factory()->create();

    $row = TenantContext::run($tenant, fn () => TenantOwnedModel::create(['label' => 'x']));

    expect($row->tenant_id)->toBe($tenant->id);
});

it('hides rows belonging to other tenants', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    TenantContext::run($a->id, fn () => TenantOwnedModel::create(['label' => 'a']));
    TenantContext::run($b->id, fn () => TenantOwnedModel::create(['label' => 'b']));

    $seenByA = TenantContext::run($a->id, fn () => TenantOwnedModel::pluck('label')->all());

    expect($seenByA)->toBe(['a']);
});

it('throws (default-deny) when querying with no tenant context', function () {
    TenantOwnedModel::query()->get();
})->throws(TenantContextMissingException::class);

it('throws (default-deny) when creating with no tenant context', function () {
    TenantOwnedModel::create(['label' => 'x']);
})->throws(TenantContextMissingException::class);

it('sees every tenant inside an audited cross block', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    TenantContext::run($a->id, fn () => TenantOwnedModel::create(['label' => 'a']));
    TenantContext::run($b->id, fn () => TenantOwnedModel::create(['label' => 'b']));

    $total = TenantContext::cross(fn () => TenantOwnedModel::count());

    expect($total)->toBe(2);
});

it("blocks writing another tenant's row while scoped to a tenant", function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    TenantContext::run($a->id, fn () => TenantOwnedModel::create([
        'tenant_id' => $b->id,
        'label' => 'leak',
    ]));
})->throws(TenantContextMissingException::class);

it('restores the previous context after nesting', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    TenantContext::run($a->id, function () use ($a, $b) {
        expect(TenantContext::id())->toBe($a->id);

        TenantContext::run($b->id, function () use ($b) {
            expect(TenantContext::id())->toBe($b->id);
        });

        expect(TenantContext::id())->toBe($a->id);
    });

    expect(TenantContext::has())->toBeFalse();
});

it('blocks moving a row to another tenant via update (app layer, no RLS)', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    $row = TenantContext::run($a->id, fn () => TenantOwnedModel::create(['label' => 'a']));

    TenantContext::run($a->id, fn () => $row->update(['tenant_id' => $b->id]));
})->throws(TenantContextMissingException::class);

it('allows updating a non-tenant field', function () {
    $a = Tenant::factory()->create();

    $row = TenantContext::run($a->id, fn () => TenantOwnedModel::create(['label' => 'before']));

    TenantContext::run($a->id, fn () => $row->update(['label' => 'after']));

    $fresh = TenantContext::run($a->id, fn () => TenantOwnedModel::find($row->id));

    expect($fresh->label)->toBe('after');
});

it('allows tenant reassignment only through the audited cross() path', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    $row = TenantContext::run($a->id, fn () => TenantOwnedModel::create(['label' => 'a']));

    TenantContext::cross(fn () => $row->update(['tenant_id' => $b->id]));

    $moved = TenantContext::run($b->id, fn () => TenantOwnedModel::find($row->id));

    expect($moved)->not->toBeNull()
        ->and($moved->tenant_id)->toBe($b->id);
});
