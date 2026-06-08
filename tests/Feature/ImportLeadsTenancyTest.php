<?php

use App\Imports\LeadsImport;
use App\Jobs\ImportLeadsJob;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextMissingException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::forget();
});

// --- D-M5-3: the catastrophic seam, proven fail-closed ---

it('writes zero leads when the target campaign belongs to another client', function () {
    Storage::fake('local');

    $clientA = Tenant::factory()->create();
    $uploader = User::factory()->create();
    $uploader->tenants()->attach($clientA);

    // A campaign that lives in a DIFFERENT client — not resolvable from A.
    $clientB = Tenant::factory()->create();
    $foreignCampaign = TenantContext::run($clientB->id, fn () => Campaign::factory()->create());

    $path = 'imports/cross-client.csv';
    writeLeadCsv($path, [
        ['9111111111', 'Asha', 'asha@example.com', 'North'],
        ['9222222222', 'Ravi', 'ravi@example.com', 'South'],
    ]);

    (new ImportLeadsJob(
        tenantId: $clientA->id,
        campaignId: $foreignCampaign->id,
        uploaderId: $uploader->id,
        storedPath: $path,
        originalName: 'cross-client.csv',
    ))->handle();

    // Refused before writing anything — neither client gained a lead.
    expect(TenantContext::run($clientA->id, fn (): int => Lead::count()))->toBe(0);
    expect(TenantContext::run($clientB->id, fn (): int => Lead::count()))->toBe(0);

    // And the temp file was cleaned up.
    expect(Storage::disk('local')->exists($path))->toBeFalse();
});

it('throws default-deny if the importer ever runs with no client context', function () {
    Storage::fake('local');

    $client = Tenant::factory()->create();
    $campaign = TenantContext::run($client->id, fn () => Campaign::factory()->create());

    $path = 'imports/no-context.csv';
    writeLeadCsv($path, [['9333333333', 'Maya', 'maya@example.com', 'East']]);

    // Without the job's TenantContext::run wrapper, the very first lead query in
    // the importer hits the default-deny wall — it can never silently write.
    expect(fn () => Excel::import(new LeadsImport($campaign), $path, 'local'))
        ->toThrow(TenantContextMissingException::class);

    expect(TenantContext::run($client->id, fn (): int => Lead::count()))->toBe(0);
});
