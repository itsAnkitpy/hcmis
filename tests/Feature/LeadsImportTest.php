<?php

use App\Enums\LeadStatus;
use App\Imports\LeadsImport;
use App\Jobs\ImportLeadsJob;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::forget();
});

/**
 * Run the importer for a campaign inside its client's context and hand back the
 * import object so its tallies (imported / duplicates / skipped) can be asserted.
 *
 * @param  array<int, array<int, string>>  $rows
 * @param  array<int, string>  $headers
 */
function importCsv(Tenant $client, Campaign $campaign, array $rows, array $headers = ['phone', 'name', 'email', 'region']): LeadsImport
{
    $path = 'imports/'.uniqid('leads_').'.csv';
    writeLeadCsv($path, $rows, $headers);

    return TenantContext::run($client->id, function () use ($campaign, $path): LeadsImport {
        $import = new LeadsImport($campaign);
        Excel::import($import, $path, 'local');

        return $import;
    });
}

// --- happy path ---

it('imports valid rows into the chosen campaign, stamped to the client', function () {
    Storage::fake('local');

    $client = Tenant::factory()->create();
    $campaign = TenantContext::run($client->id, fn () => Campaign::factory()->create());

    $import = importCsv($client, $campaign, [
        ['9111111111', 'Asha', 'asha@example.com', 'North'],
        ['9222222222', 'Ravi', 'ravi@example.com', 'South'],
        ['9333333333', 'Maya', 'maya@example.com', 'East'],
    ]);

    expect($import->imported)->toBe(3)
        ->and($import->duplicates)->toBe(0)
        ->and($import->skipped)->toHaveCount(0);

    $leads = TenantContext::run($client->id, fn () => Lead::orderBy('phone')->get());

    expect($leads)->toHaveCount(3)
        ->and($leads->pluck('tenant_id')->unique()->all())->toBe([$client->id])
        ->and($leads->pluck('campaign_id')->unique()->all())->toBe([$campaign->id])
        ->and($leads->pluck('status')->unique()->all())->toBe([LeadStatus::New])
        ->and($leads->first()->name)->toBe('Asha');
});

// --- validation: skip-and-report (D-M5-6) ---

it('skips rows with a missing or invalid phone and a bad email, importing the rest', function () {
    Storage::fake('local');

    $client = Tenant::factory()->create();
    $campaign = TenantContext::run($client->id, fn () => Campaign::factory()->create());

    $import = importCsv($client, $campaign, [
        ['9111111111', 'Valid', 'valid@example.com', 'North'],
        ['', 'No Phone', 'nophone@example.com', 'South'],          // missing phone
        ['abc', 'Bad Phone', 'badphone@example.com', 'East'],       // non-numeric phone
        ['9444444444', 'Bad Email', 'not-an-email', 'West'],        // invalid email
        ['9555555555', 'Also Valid', 'also@example.com', 'North'],
    ]);

    expect($import->imported)->toBe(2)
        ->and($import->skipped)->toHaveCount(3)
        ->and(collect($import->skipped)->pluck('reason')->all())->toContain('phone is required')
        ->and(collect($import->skipped)->pluck('reason')->all())->toContain('phone is not a valid number')
        ->and(collect($import->skipped)->pluck('reason')->all())->toContain('email is not a valid address');

    expect(TenantContext::run($client->id, fn (): int => Lead::count()))->toBe(2);
});

// --- dedupe (D-M5-7): skip repeats within the client ---

it('skips a phone that already exists in the client and within-file repeats', function () {
    Storage::fake('local');

    $client = Tenant::factory()->create();
    $campaign = TenantContext::run($client->id, function () {
        $campaign = Campaign::factory()->create();
        Lead::factory()->forCampaign($campaign)->create(['phone' => '9111111111']);

        return $campaign;
    });

    $import = importCsv($client, $campaign, [
        ['9111111111', 'Already Here', 'dup@example.com', 'North'],  // existing in client
        ['9222222222', 'Fresh', 'fresh@example.com', 'South'],
        ['9222222222', 'Same File Dup', 'dup2@example.com', 'East'],  // repeat within file
    ]);

    expect($import->imported)->toBe(1)
        ->and($import->duplicates)->toBe(2);

    // One pre-existing + one freshly imported = 2 total in the client.
    expect(TenantContext::run($client->id, fn (): int => Lead::count()))->toBe(2);
});

// --- custom fields (D-M5-5): match slugged columns, enforce required ---

it('maps matching columns into custom_fields and skips rows missing a required one', function () {
    Storage::fake('local');

    $client = Tenant::factory()->create();
    $campaign = TenantContext::run($client->id, fn () => Campaign::factory()->withCustomFields([
        ['key' => 'policy_number', 'label' => 'Policy Number', 'type' => 'text', 'required' => true],
        ['key' => 'plan', 'label' => 'Plan', 'type' => 'select', 'required' => false, 'options' => ['Gold', 'Silver']],
    ])->create());

    // Header uses human spacing/caps for the custom column — slug matching aligns it.
    $import = importCsv(
        $client,
        $campaign,
        [
            ['9111111111', 'With Policy', 'a@example.com', 'North', 'POL-123', 'Gold'],
            ['9222222222', 'No Policy', 'b@example.com', 'South', '', 'Silver'],   // required policy missing
        ],
        ['phone', 'name', 'email', 'region', 'Policy Number', 'Plan'],
    );

    expect($import->imported)->toBe(1)
        ->and($import->skipped)->toHaveCount(1)
        ->and($import->skipped[0]['reason'])->toBe("required field 'Policy Number' is missing");

    $lead = TenantContext::run($client->id, fn () => Lead::first());

    // Postgres jsonb does not preserve key insertion order — compare by content.
    expect($lead->custom_fields)->toEqual(['policy_number' => 'POL-123', 'plan' => 'Gold']);
});

// --- XLSX is accepted too (FR-LC01 names both formats) ---

it('imports an XLSX file the same way as CSV', function () {
    Storage::fake('local');

    $client = Tenant::factory()->create();
    $campaign = TenantContext::run($client->id, fn () => Campaign::factory()->create());

    $path = 'imports/leads.xlsx';
    Storage::disk('local')->makeDirectory('imports');
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray([
        ['phone', 'name', 'email', 'region'],
        ['9111111111', 'Asha', 'asha@example.com', 'North'],
        ['9222222222', 'Ravi', 'ravi@example.com', 'South'],
    ], null, 'A1');
    (new Xlsx($spreadsheet))->save(Storage::disk('local')->path($path));

    $import = TenantContext::run($client->id, function () use ($campaign, $path): LeadsImport {
        $import = new LeadsImport($campaign);
        Excel::import($import, $path, 'local');

        return $import;
    });

    expect($import->imported)->toBe(2);
    expect(TenantContext::run($client->id, fn (): int => Lead::count()))->toBe(2);
});

// --- isolation: an import into one client is invisible to another ---

it('imports leads into the uploader\'s client only, never another client', function () {
    Storage::fake('local');

    $clientA = Tenant::factory()->create();
    $clientB = Tenant::factory()->create();
    $uploader = User::factory()->create();
    $uploader->tenants()->attach($clientA);
    $campaignA = TenantContext::run($clientA->id, fn () => Campaign::factory()->create());

    $path = 'imports/isolation.csv';
    writeLeadCsv($path, [
        ['9111111111', 'Asha', 'asha@example.com', 'North'],
        ['9222222222', 'Ravi', 'ravi@example.com', 'South'],
    ]);

    (new ImportLeadsJob(
        tenantId: $clientA->id,
        campaignId: $campaignA->id,
        uploaderId: $uploader->id,
        storedPath: $path,
        originalName: 'isolation.csv',
    ))->handle();

    expect(TenantContext::run($clientA->id, fn (): int => Lead::count()))->toBe(2)
        ->and(TenantContext::run($clientB->id, fn (): int => Lead::count()))->toBe(0);
});

// --- job end-to-end: leads land, file cleaned up, uploader notified ---

it('runs the full job: imports leads, deletes the file, notifies the uploader', function () {
    Storage::fake('local');

    $client = Tenant::factory()->create();
    $uploader = User::factory()->create();
    $uploader->tenants()->attach($client);
    $campaign = TenantContext::run($client->id, fn () => Campaign::factory()->create());

    $path = 'imports/job-run.csv';
    writeLeadCsv($path, [
        ['9111111111', 'Asha', 'asha@example.com', 'North'],
        ['9111111111', 'Dup', 'dup@example.com', 'South'],   // within-file duplicate
        ['', 'No Phone', 'x@example.com', 'East'],            // skipped
    ]);

    (new ImportLeadsJob(
        tenantId: $client->id,
        campaignId: $campaign->id,
        uploaderId: $uploader->id,
        storedPath: $path,
        originalName: 'job-run.csv',
    ))->handle();

    expect(TenantContext::run($client->id, fn (): int => Lead::count()))->toBe(1)
        ->and(Storage::disk('local')->exists($path))->toBeFalse();

    $notification = $uploader->notifications()->first();

    expect($notification)->not->toBeNull()
        ->and($notification->data['title'] ?? '')->toContain('1 added');
});
