<?php

use App\Enums\CampaignTemplate;
use App\Models\Campaign;
use App\Models\Disposition;
use App\Models\DncEntry;
use App\Models\Lead;
use App\Models\Script;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextMissingException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::forget();
});

// Every M4.B model is tenant-owned: it must carry the BelongsToTenant trait
// (app-layer scope + default-deny) AND have RLS enabled on its table. The
// dataset proves both for each model in one place.
dataset('tenantModels', [
    'campaign' => [Campaign::class],
    'disposition' => [Disposition::class],
    'dnc entry' => [DncEntry::class],
    'lead' => [Lead::class],
    'script' => [Script::class],
]);

/**
 * @param  class-string<Model>  $model
 */
function seedOnePerTenant(string $model, Tenant $a, Tenant $b): void
{
    TenantContext::run($a->id, fn () => $model::factory()->create());
    TenantContext::run($b->id, fn () => $model::factory()->create());
}

// --- Layer 2: app-layer Eloquent scoping ---

it('scopes reads to the active tenant', function (string $model) {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    seedOnePerTenant($model, $a, $b);

    $count = TenantContext::run($a->id, fn () => $model::count());

    expect($count)->toBe(1);
})->with('tenantModels');

it('default-denies a read when no tenant is set', function (string $model) {
    $model::query()->count();
})->with('tenantModels')->throws(TenantContextMissingException::class);

// --- Layer 3: Postgres RLS (proven by bypassing the Eloquent scope) ---

it('blocks a raw cross-tenant SELECT at the database via RLS', function (string $model) {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    seedOnePerTenant($model, $a, $b);

    $table = (new $model)->getTable();
    $rows = TenantContext::run($a->id, fn () => DB::select("select tenant_id from {$table}"));

    expect($rows)->toHaveCount(1)
        ->and((int) $rows[0]->tenant_id)->toBe($a->id);
})->with('tenantModels');

// --- cross-tenant WRITE denied (trait guard + RLS WITH CHECK) ---

it('refuses to write a row scoped to another tenant (trait guard)', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    TenantContext::run($a->id, function () use ($b) {
        $campaign = Campaign::factory()->make(); // unsaved, no tenant_id yet
        $campaign->tenant_id = $b->id;           // explicit cross-tenant write from A's context
        $campaign->save();
    });
})->throws(TenantContextMissingException::class);

it('blocks a raw cross-tenant INSERT into leads via RLS WITH CHECK', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $campaignA = TenantContext::run($a->id, fn () => Campaign::factory()->create());

    expect(fn () => TenantContext::run($a->id, fn () => DB::insert(
        'insert into leads (tenant_id, campaign_id, phone, status, attempts, custom_fields) values (?, ?, ?, ?, ?, ?)',
        [$b->id, $campaignA->id, '9990001111', 'new', 0, '{}'],
    )))->toThrow(QueryException::class);
});

// --- model graph + casts within a tenant ---

it('wires the campaign / lead / disposition / script graph within a tenant', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function () {
        $campaign = Campaign::factory()->template(CampaignTemplate::OutboundSales)->create();
        $disposition = Disposition::factory()->forCampaign($campaign)->sale()->create();
        $lead = Lead::factory()->forCampaign($campaign)->create(['last_disposition_id' => $disposition->id]);
        Script::factory()->forCampaign($campaign)->create();

        expect($campaign->template)->toBe(CampaignTemplate::OutboundSales)
            ->and($campaign->is_active)->toBeTrue()
            ->and($campaign->custom_fields)->toBe([])
            ->and($lead->campaign->is($campaign))->toBeTrue()
            ->and($lead->lastDisposition->is_sale)->toBeTrue()
            ->and($campaign->leads()->count())->toBe(1)
            ->and($campaign->dispositions()->count())->toBe(1)
            ->and($campaign->scripts()->count())->toBe(1);
    });
});
