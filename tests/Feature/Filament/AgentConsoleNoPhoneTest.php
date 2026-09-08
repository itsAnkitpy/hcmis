<?php

use App\Enums\CallbackStatus;
use App\Enums\RoleName;
use App\Filament\Pages\AgentConsole;
use App\Models\Callback;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\Tenant;
use App\Telephony\AgentPhoneWriter;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * SEC-1 slice 4 (PP-12/PP-13) — what an agent with no phone of their own sees.
 *
 * 🔴 This file runs the REAL directory on purpose. Every other console test stubs it
 * (fakeAgentDirectory) because they are about call mechanics; the whole point here is
 * the refusal that replaced the silent fallback, so a stub would prove nothing.
 *
 * The refusal used to be impossible to reach: an agent who was not in the config map
 * got the one shared extension AND the one shared password, so their browser
 * registered as agent 1003's phone and their outbound leg rang 1003's desk. Two
 * people, one phone, no error anywhere.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    createAsteriskPhoneTables();
    config()->set('telephony.agent.ws_url', 'ws://127.0.0.1:8088/ws');
    config()->set('telephony.agent.sip_domain', 'asterisk.lab');
    // The dead fallback, left populated so every refusal below is asserted with a real
    // extension and a real password sitting right there to be handed out.
    config()->set('telephony.agent.extension', '1003');
    config()->set('telephony.agent.password', 'legacy-secret');
    config()->set('telephony.agent.endpoint', 'PJSIP/1003');

    $this->tenant = Tenant::factory()->create();
    $this->agent = clientUserWithRole($this->tenant, RoleName::Agent->value);
    $this->actingAs($this->agent);
});

afterEach(function () {
    TenantContext::resetWebRequest();
    TenantContext::forget();
});

/**
 * The console's markup as the browser receives it. `@js` emits the identity inside a
 * `JSON.parse('…')` call with its quotes written as `\u0022`, so they are put back
 * before asserting — the test is about the values, not about Blade's escaping.
 */
function consoleHtml(): string
{
    return str_replace('\u0022', '"', Livewire::test(AgentConsole::class)->html());
}

// --- PP-12: the identity the browser is handed ---

it('hands the browser no identity at all when the agent holds no phone', function () {
    $config = TenantContext::run($this->tenant->id, fn (): array => (new AgentConsole)->getPhoneConfig());

    expect($config['extension'])->toBeNull()
        ->and($config['password'])->toBeNull()
        ->and($config['wsUrl'])->toBe('ws://127.0.0.1:8088/ws');   // the shared bits still travel
});

it('hands the browser no identity for a retired agent — the number survives, the key does not', function () {
    $extension = app(AgentPhoneWriter::class)->provisionFor($this->agent);
    app(AgentPhoneWriter::class)->retireFor($this->agent->fresh());

    $config = TenantContext::run($this->tenant->id, fn (): array => (new AgentConsole)->getPhoneConfig());

    expect($config['extension'])->toBeNull()
        ->and($config['password'])->toBeNull()
        ->and($this->agent->fresh()->sip_extension)->toBe($extension);
});

// --- PP-13: a data test is not a markup test ---

/**
 * 🔴 The identity has to arrive as Alpine state fed by a RETURN VALUE, and the way to
 * prove it is to look at the HTML the browser actually receives. S120b: one
 * #[Renderless] method anywhere in a Livewire batch drops the HTML for the whole
 * request, and the console ships renderless methods — a screen can be provably correct
 * in a `call()` test and render nothing at all.
 */
it('carries the refusal into the rendered page, not just into a method return', function () {
    TenantContext::applyWebRequest($this->tenant->id, crossTenant: false);

    $html = consoleHtml();

    expect($html)->toContain('agentConsole(')            // the component mounted at all
        ->and($html)->toContain('"extension":null')      // …carrying no identity
        ->and($html)->not->toContain('legacy-secret');   // …and never the shared password
});

it('carries a real agent\'s own extension into the rendered page', function () {
    $extension = app(AgentPhoneWriter::class)->provisionFor($this->agent);
    TenantContext::applyWebRequest($this->tenant->id, crossTenant: false);

    $html = consoleHtml();

    expect($html)->toContain('"extension":"'.$extension.'"')
        ->and($html)->not->toContain('"extension":null');
});

// --- PP-12: every dial path refuses ---

it('refuses the served-lead dial when the agent holds no phone', function () {
    Http::preventStrayRequests();   // a refused dial must never originate

    $campaignId = TenantContext::run($this->tenant->id, function (): int {
        $campaign = Campaign::factory()->create(['is_active' => true]);
        Lead::factory()->forCampaign($campaign)->create(['phone' => '9991234567', 'attempts' => 0]);

        return $campaign->id;
    });

    $result = TenantContext::run($this->tenant->id, function () use ($campaignId): array {
        $page = new AgentConsole;
        $page->selectedCampaignId = $campaignId;

        return $page->dial();
    });

    expect($result)->toBe(['outcome' => 'nophone']);
});

it('refuses an ad-hoc dial when the agent holds no phone', function () {
    Http::preventStrayRequests();

    $result = TenantContext::run($this->tenant->id, fn (): array => (new AgentConsole)->dialAdhoc('9997654321'));

    expect($result)->toBe(['outcome' => 'nophone']);
});

/**
 * 🔴 The refusal comes BEFORE the callback is consumed. Dialing a callback flips it to
 * done so it leaves the due-list; refusing after that flip would drop a real callback
 * off the agent's list for a call that never happened.
 */
it('refuses a callback dial without consuming the callback', function () {
    Http::preventStrayRequests();

    $callbackId = TenantContext::run($this->tenant->id, function (): int {
        $campaign = Campaign::factory()->create(['is_active' => true]);
        $lead = Lead::factory()->forCampaign($campaign)->create(['phone' => '9991234567']);

        return Callback::factory()->forLead($lead)->forAgent($this->agent)->due()->create()->id;
    });

    $result = TenantContext::run($this->tenant->id, fn (): array => (new AgentConsole)->dialCallback($callbackId));
    $callback = TenantContext::run($this->tenant->id, fn () => Callback::query()->find($callbackId));

    expect($result)->toBe(['outcome' => 'nophone'])
        ->and($callback->status)->toBe(CallbackStatus::Pending);   // still on the due-list
});

// --- the guard is a refusal, not a wall ---

it('lets an agent who holds a phone dial on their own extension', function () {
    $extension = app(AgentPhoneWriter::class)->provisionFor($this->agent);
    Http::fake(['*' => Http::response(['id' => 'agent-leg'])]);

    $result = TenantContext::run($this->tenant->id, fn (): array => (new AgentConsole)->dialAdhoc('9997654321'));

    expect($result['outcome'])->toBe('dialed');
    Http::assertSent(function ($request) use ($extension): bool {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $params);

        return str_contains($request->url(), '/ari/channels?') && $params['endpoint'] === 'PJSIP/'.$extension;
    });
});
