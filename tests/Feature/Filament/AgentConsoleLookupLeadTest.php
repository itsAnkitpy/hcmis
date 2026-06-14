<?php

use App\Enums\LeadStatus;
use App\Filament\Pages\AgentConsole;
use App\Models\Campaign;
use App\Models\Disposition;
use App\Models\Lead;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::forget();
});

/**
 * B4 D4 lead lookup: the ringing screen asks the page to match the caller's
 * number to a lead. The match runs in the agent's tenant context, so the tenant
 * walls (BelongsToTenant + RLS) decide what is visible. These prove the happy
 * match, the cross-client wall, the unknown number, and that a formatted
 * caller-ID still matches the bare stored phone (PhoneNumber normalization).
 */
function lookupCaller(int $tenantId, string $number): ?array
{
    return TenantContext::run($tenantId, fn (): ?array => (new AgentConsole)->lookupLead($number));
}

it('returns the matching lead shape for a number in the agent client', function () {
    $tenant = Tenant::factory()->create();

    $leadId = TenantContext::run($tenant->id, function (): int {
        $campaign = Campaign::factory()->create(['name' => 'Summer Push']);
        $disposition = Disposition::factory()->forCampaign($campaign)->create(['label' => 'Call Back']);

        return Lead::factory()->forCampaign($campaign)->create([
            'phone' => '9991234567',
            'name' => 'Alice Caller',
            'status' => LeadStatus::Contacted,
            'last_disposition_id' => $disposition->id,
        ])->id;
    });

    expect(lookupCaller($tenant->id, '9991234567'))->toBe([
        'id' => $leadId,
        'name' => 'Alice Caller',
        'phone' => '9991234567',
        'campaign' => 'Summer Push',
        'status' => 'Contacted',
        'lastDisposition' => 'Call Back',
    ]);
});

it('does not match the same number when it belongs to another client (tenant wall)', function () {
    $clientA = Tenant::factory()->create();
    $clientB = Tenant::factory()->create();

    TenantContext::run($clientB->id, fn () => Lead::factory()->create(['phone' => '9991234567']));

    // Scoped to client A, B's lead with the very same number is invisible.
    expect(lookupCaller($clientA->id, '9991234567'))->toBeNull();
});

it('returns null when no lead matches the number', function () {
    $tenant = Tenant::factory()->create();
    TenantContext::run($tenant->id, fn () => Lead::factory()->create(['phone' => '9991234567']));

    expect(lookupCaller($tenant->id, '8887776666'))->toBeNull();
});

it('matches a formatted caller-ID against the bare stored phone', function () {
    $tenant = Tenant::factory()->create();
    $leadId = TenantContext::run($tenant->id, fn (): int => Lead::factory()->create(['phone' => '9991234567'])->id);

    $result = lookupCaller($tenant->id, '999-123 4567');

    expect($result)->not->toBeNull()
        ->and($result['id'])->toBe($leadId);
});
