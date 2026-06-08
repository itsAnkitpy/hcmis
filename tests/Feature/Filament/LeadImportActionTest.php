<?php

use App\Enums\RoleName;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Jobs\ImportLeadsJob;
use App\Models\Campaign;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::resetWebRequest();
    TenantContext::forget();
});

/**
 * A team_leader pinned to a fresh client, with the web-path context applied —
 * the posture SetCurrentTenant gives client-side staff on a panel request.
 */
function actImportTeamLeader(): Tenant
{
    $tenant = Tenant::factory()->create();
    $teamLeader = clientUserWithRole($tenant, RoleName::TeamLeader->value);

    test()->actingAs($teamLeader->fresh());
    TenantContext::applyWebRequest($tenant->id, false);

    return $tenant;
}

it('dispatches the import job for the team leader\'s client and chosen campaign', function () {
    Storage::fake('local');
    Queue::fake();

    $tenant = actImportTeamLeader();
    $campaign = Campaign::factory()->create();

    $file = UploadedFile::fake()->createWithContent(
        'leads.csv',
        "phone,name,email,region\n9111111111,Asha,asha@example.com,North",
    );

    Livewire::test(ListLeads::class)
        ->callAction('importLeads', data: [
            'campaign_id' => $campaign->id,
            'file' => [$file],
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Import started');

    Queue::assertPushed(
        ImportLeadsJob::class,
        fn (ImportLeadsJob $job): bool => $job->tenantId === $tenant->id
            && $job->campaignId === $campaign->id,
    );
});

it('downloads a CSV template carrying the campaign\'s custom-field columns', function () {
    actImportTeamLeader();

    $campaign = Campaign::factory()->withCustomFields([
        ['key' => 'policy_number', 'label' => 'Policy Number', 'type' => 'text', 'required' => true],
    ])->create();

    Livewire::test(ListLeads::class)
        ->callAction('downloadTemplate', data: ['campaign_id' => $campaign->id])
        ->assertFileDownloaded('lead-import-template.csv');
});

it('hides the import action from read-only roles (D-M4-5)', function (string $role) {
    $tenant = Tenant::factory()->create();
    $user = clientUserWithRole($tenant, $role);

    test()->actingAs($user->fresh());
    TenantContext::applyWebRequest($tenant->id, false);

    Livewire::test(ListLeads::class)
        ->assertActionHidden('importLeads')
        ->assertActionHidden('downloadTemplate');
})->with([
    'qc' => RoleName::Qc->value,
    'trainer' => RoleName::Trainer->value,
]);
