<?php

use App\Enums\RoleName;
use App\Filament\Pages\AgentConsole;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::resetWebRequest();
    TenantContext::forget();
});

/**
 * F7, the markup half (campaign-fields-on-console.md).
 *
 * 🔴 The behaviour this guards is in Alpine, not in PHP: saveWrapUp() and
 * completeUnmatched() used to reset the screen to Ready in a `finally`, so a refused
 * save destroyed the call row, the agent's note and the typed customer details at once
 * and showed nothing. They now reset only after the server has taken it, and write the
 * reason into `wrapUpNotice` when it has not.
 *
 * No Livewire data test can see that — `saveWrapUp()` succeeding server-side is exactly
 * the case where nothing goes wrong. What a test CAN pin is that both panels still carry
 * somewhere to show the reason. Delete either span, or rename the state, and the agent is
 * back to a silent reset with no signal. That is the failure worth catching.
 *
 * CF-4 is what starts refusing. Until it lands this notice stays null on every call.
 */
it('carries a place to show a refused wrap-up on both panels', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $this->actingAs($agent);
    TenantContext::applyWebRequest($tenant->id, crossTenant: false);

    $html = Livewire::test(AgentConsole::class)->html();

    // Two bindings: one beside the matched panel's Save, one beside the no-match Done.
    expect(substr_count($html, 'x-text="wrapUpNotice"'))->toBe(2)
        ->and(substr_count($html, 'x-show="wrapUpNotice"'))->toBe(2);
});
