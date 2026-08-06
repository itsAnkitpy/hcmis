<?php

use App\Models\Campaign;
use App\Models\PhoneNumber;
use App\Models\Tenant;
use App\Telephony\NumberDirectory;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextMissingException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::forget();
});

/**
 * The number -> client lookup (B2.3a ND-1): the thing that replaced the one hardcoded
 * client the dialplan used to stamp on every inbound call.
 *
 * It runs COMPANY-BLIND on purpose — finding the client is the whole point — so these
 * tests deliberately call it with NO tenant context at all, which is exactly how the
 * telephony listener calls it. That posture is the risk worth covering: it must find
 * the one owner and nothing else.
 */
function numberDirectory(): NumberDirectory
{
    return new NumberDirectory;
}

it('finds the client that owns a dialled number, with no tenant context set', function () {
    $tenant = Tenant::factory()->create();
    TenantContext::run($tenant->id, fn () => PhoneNumber::factory()->create(['number' => '+919876543210']));

    TenantContext::forget();   // the listener has no client in scope — that is the point

    $resolved = numberDirectory()->resolve('+919876543210');

    expect($resolved)->not->toBeNull()
        ->and($resolved->tenant_id)->toBe($tenant->id);
});

it('carries the campaign the number is assigned to (the ND-6 seam)', function () {
    $tenant = Tenant::factory()->create();

    [$campaign, $number] = TenantContext::run($tenant->id, function (): array {
        $campaign = Campaign::factory()->create();

        return [$campaign, PhoneNumber::factory()->forCampaign($campaign)->create()];
    });

    TenantContext::forget();

    expect(numberDirectory()->resolve($number->number)->campaign_id)->toBe($campaign->id);
});

it('returns nothing for a switched-off number (ND-4 clean end)', function () {
    $tenant = Tenant::factory()->create();
    TenantContext::run($tenant->id, fn () => PhoneNumber::factory()->inactive()->create(['number' => '+919876543210']));

    TenantContext::forget();

    expect(numberDirectory()->resolve('+919876543210'))->toBeNull();
});

it('returns nothing for a number nobody owns', function () {
    Tenant::factory()->create();
    TenantContext::forget();

    expect(numberDirectory()->resolve('+919999999999'))->toBeNull();
});

it('returns nothing when the call carries no dialled number at all', function () {
    expect(numberDirectory()->resolve(null))->toBeNull()
        ->and(numberDirectory()->resolve(''))->toBeNull();
});

it('never hands back another client when two clients both own numbers', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    TenantContext::run($a->id, fn () => PhoneNumber::factory()->create(['number' => '+911111111111']));
    TenantContext::run($b->id, fn () => PhoneNumber::factory()->create(['number' => '+912222222222']));

    TenantContext::forget();

    expect(numberDirectory()->resolve('+911111111111')->tenant_id)->toBe($a->id)
        ->and(numberDirectory()->resolve('+912222222222')->tenant_id)->toBe($b->id);
});

it('leaves no tenant pinned behind it, so the caller keeps whatever posture it had', function () {
    $tenant = Tenant::factory()->create();
    TenantContext::run($tenant->id, fn () => PhoneNumber::factory()->create(['number' => '+919876543210']));

    TenantContext::forget();
    numberDirectory()->resolve('+919876543210');

    expect(TenantContext::has())->toBeFalse()
        ->and(TenantContext::isCrossTenant())->toBeFalse();
});

// --- ND-2: one number, one owner, enforced by the database ---

it('refuses the same number for a second client at the database (ND-2)', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    TenantContext::run($a->id, fn () => PhoneNumber::factory()->create(['number' => '+919876543210']));
    TenantContext::run($b->id, fn () => PhoneNumber::factory()->create(['number' => '+919876543210']));
})->throws(QueryException::class);

it('refuses to create a number with no client in scope', function () {
    PhoneNumber::factory()->create(['number' => '+919876543210']);
})->throws(TenantContextMissingException::class);
