<?php

use App\Enums\CallDirection;
use App\Enums\RoleName;
use App\Filament\Pages\AgentConsole;
use App\Models\Call;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::resetWebRequest();
    TenantContext::forget();
});

/**
 * CH-2, over the wire the browser actually uses (customer-history-panel.md).
 *
 * 🔴 The panel is NOT server-rendered, and these tests exist because the first version
 * was. Livewire batches the $wire calls made in one tick, and one #[Renderless] method
 * anywhere in the batch drops the HTML for the whole request — the console ships
 * dialAdhoc() together with setPresence(), which is renderless, so a Blade-rendered panel
 * was built and thrown away before it reached the DOM. Every test passed; nothing showed
 * on the screen. The panel now reads callHistory()'s RETURN VALUE into Alpine state, the
 * way the lead card always has.
 *
 * So the contract worth pinning is the one the browser depends on: after a dial, the
 * console's own held number answers callHistory() with this customer's calls, and the
 * blade carries the markup that renders them.
 */
function consoleAfterAdhocDial(Tenant $tenant, User $agent, string $number): Testable
{
    config()->set('telephony.agent.endpoint', 'PJSIP/1003');
    Http::fake(['*' => Http::response(['id' => 'agent-leg'])]);

    test()->actingAs($agent);
    TenantContext::applyWebRequest($tenant->id, crossTenant: false);

    return Livewire::test(AgentConsole::class)->call('dialAdhoc', $number);
}

it('answers with the previous calls once a dial has set the number', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    TenantContext::run($tenant->id, function (): void {
        Call::factory()->forAgent(User::factory()->create(['name' => 'Abhikesh']))->create([
            'direction' => CallDirection::Outbound,
            'to_number' => '+919805907341',
        ]);
    });

    consoleAfterAdhocDial($tenant, $agent, '+919805907341')
        ->call('callHistory')
        ->assertReturned(fn (array $history): bool => count($history['calls']) === 1
            && $history['calls'][0]['agent'] === 'Abhikesh'
            && $history['calls'][0]['direction'] === 'Outbound');
});

it('answers with nothing for a number it has never called', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    consoleAfterAdhocDial($tenant, $agent, '+919805907341')
        ->call('callHistory')
        ->assertReturned(fn (array $history): bool => $history['calls'] === [] && $history['callbacks'] === []);
});

// The markup half. `callHistory()` returning the right rows is worth nothing if the panel
// that renders them has been deleted or its state renamed — the exact failure this whole
// round trip was about, and the one no data test can see.
it('carries the panel markup bound to the history state', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $this->actingAs($agent);
    TenantContext::applyWebRequest($tenant->id, crossTenant: false);

    $html = Livewire::test(AgentConsole::class)->html();

    expect($html)->toContain('call in history.calls')
        ->and($html)->toContain('callback in history.callbacks')
        ->and($html)->toContain('First time we are speaking to this number.');
});
