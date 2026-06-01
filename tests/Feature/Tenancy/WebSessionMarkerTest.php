<?php

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Guards the one catastrophic implementation detail of the web wall: the marker
 * MUST be stamped with plain SET (session-scoped), NOT SET LOCAL. A web request
 * has no request-long transaction, so SET LOCAL would evaporate immediately and
 * RLS would default-deny every row.
 *
 * This file deliberately does NOT use RefreshDatabase — its per-test wrapping
 * transaction would make even SET LOCAL appear to persist, hiding the bug. With
 * no wrapping transaction here, SET LOCAL outside a transaction is discarded by
 * Postgres, so these assertions fail the moment someone "optimises" the web
 * path to SET LOCAL.
 */
afterEach(function () {
    TenantContext::resetWebRequest();
});

function tenantGuc(): ?string
{
    return DB::scalar("select current_setting('app.current_tenant_id', true)");
}

function bypassGuc(): ?string
{
    return DB::scalar("select current_setting('app.bypass_rls', true)");
}

it('stamps a pinned client marker that survives outside a transaction', function () {
    TenantContext::applyWebRequest(5, crossTenant: false);

    expect(tenantGuc())->toBe('5')
        ->and(bypassGuc())->toBe('off');
});

it('stamps the cross-tenant bypass for a global request with no client', function () {
    TenantContext::applyWebRequest(null, crossTenant: true);

    expect(tenantGuc())->toBe('')
        ->and(bypassGuc())->toBe('on');
});

it('wipes the marker to the safe default-deny state on reset', function () {
    TenantContext::applyWebRequest(7, crossTenant: false);
    TenantContext::resetWebRequest();

    expect(tenantGuc())->toBe('')
        ->and(bypassGuc())->toBe('off');
});
