<?php

use App\Jobs\ImportLeadsJob;
use App\Models\ActivityLog;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::forget();
});

/**
 * M7 finding 3/5: a bulk import is audited as ONE attributable summary event,
 * NOT one row per lead (D-M7-2), and the no-auth queue path (M5 seam) writes the
 * event with the explicit uploader as causer without ever throwing.
 */
it('records a bulk import as a single summary event with no per-lead rows', function () {
    Storage::fake('local');

    $client = Tenant::factory()->create();
    $uploader = User::factory()->create();
    $uploader->tenants()->attach($client);
    $campaign = TenantContext::run($client->id, fn () => Campaign::factory()->create());

    $path = 'imports/audit.csv';
    writeLeadCsv($path, [
        ['9111111111', 'Asha', 'asha@example.com', 'North'],
        ['9222222222', 'Ravi', 'ravi@example.com', 'South'],
        ['9111111111', 'Dup', 'dup@example.com', 'East'],   // within-file duplicate
    ]);

    (new ImportLeadsJob(
        tenantId: $client->id,
        campaignId: $campaign->id,
        uploaderId: $uploader->id,
        storedPath: $path,
        originalName: 'audit.csv',
    ))->handle();

    [$summaries, $perLeadCreations] = TenantContext::cross(fn (): array => [
        ActivityLog::query()->where('log_name', 'import')->where('event', 'imported')->get(),
        ActivityLog::query()->where('subject_type', Lead::class)->where('event', 'created')->count(),
    ]);

    expect($summaries)->toHaveCount(1);

    $event = $summaries->first();

    expect($event->tenant_id)->toBe($client->id)              // stamped to the client
        ->and($event->causer_id)->toBe($uploader->id)         // attributed to the uploader
        ->and($event->subject_id)->toBe($campaign->id)        // linked to the campaign
        ->and($event->properties['imported'])->toBe(2)
        ->and($event->properties['duplicates'])->toBe(1)
        ->and($perLeadCreations)->toBe(0);                    // per-lead auto-logging suppressed
});
