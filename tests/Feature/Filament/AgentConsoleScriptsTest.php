<?php

use App\Enums\RoleName;
use App\Enums\ScriptType;
use App\Filament\Pages\AgentConsole;
use App\Models\Campaign;
use App\Models\Script;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::resetWebRequest();
    TenantContext::forget();
});

beforeEach(function () {
    Http::fake(['*' => Http::response(['id' => 'agent-leg'])]);
});

/**
 * N2 (dialshree-inspired-roadmap.md #21) — the call script reaches the agent's live-call
 * screen, on `callHistory()`'s return value.
 *
 * 🔴 On the return value and not a render, for the reason every panel on this console is:
 * one #[Renderless] method anywhere in a Livewire batch drops the HTML for the whole
 * request, and the console ships setPresence(). A server-rendered panel is built and
 * thrown away before it reaches the DOM, with every test still green.
 *
 * Scoped the way dispositions are — null campaign_id is tenant-wide, a set one belongs to
 * that campaign — and the campaign's row WINS. An agent reads one opening out loud.
 */
function consoleOnCampaign(?Campaign $campaign): AgentConsole
{
    $page = new AgentConsole;
    $page->callPartyNumber = '9991234567';
    $page->matchedCampaignId = $campaign?->id;

    return $page;
}

it('shows the campaign script and hides the tenant-wide one of the same type', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $this->actingAs($agent);

    $scripts = TenantContext::run($tenant->id, function (): array {
        $acme = Campaign::factory()->create(['name' => 'Acme Care Callbacks']);

        Script::factory()->type(ScriptType::Opening)->create(['content' => 'Thank you for calling.']);
        Script::factory()->type(ScriptType::Opening)->forCampaign($acme)
            ->create(['content' => 'Thank you for calling Acme Care.']);

        return consoleOnCampaign($acme)->callHistory()['scripts'];
    });

    // Priya sees ONE opening. Two on screen is a decision made mid-sentence with Meera
    // listening, which is the whole reason this precedence was settled before building.
    expect($scripts)->toHaveCount(1)
        ->and($scripts[0]['type'])->toBe('opening')
        ->and($scripts[0]['label'])->toBe('Opening')
        ->and($scripts[0]['content'])->toBe('Thank you for calling Acme Care.');
});

it('keeps the tenant-wide script for a type the campaign has not written', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $this->actingAs($agent);

    $scripts = TenantContext::run($tenant->id, function (): array {
        $acme = Campaign::factory()->create(['name' => 'Acme Care Callbacks']);

        Script::factory()->type(ScriptType::Objection)->create(['content' => 'I understand the concern.']);
        Script::factory()->type(ScriptType::Opening)->forCampaign($acme)
            ->create(['content' => 'Thank you for calling Acme Care.']);

        return consoleOnCampaign($acme)->callHistory()['scripts'];
    });

    // The campaign shadows a type it has written and nothing else — so a client who only
    // customises their opening still gets the house objection handler.
    expect(array_column($scripts, 'content'))
        ->toBe(['Thank you for calling Acme Care.', 'I understand the concern.'])
        // Enum order, which is the order a call uses them. Closing is simply absent
        // rather than an empty tab, because nobody has written one.
        ->and(array_column($scripts, 'type'))->toBe(['opening', 'objection']);
});

it('answers with the tenant-wide scripts when no campaign matched', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $this->actingAs($agent);

    $scripts = TenantContext::run($tenant->id, function (): array {
        $acme = Campaign::factory()->create(['name' => 'Acme Care Callbacks']);

        Script::factory()->type(ScriptType::Closing)->create(['content' => 'Thanks for your time.']);
        Script::factory()->type(ScriptType::Closing)->forCampaign($acme)
            ->create(['content' => 'Acme thanks you.']);

        return consoleOnCampaign(null)->callHistory()['scripts'];
    });

    // An ad-hoc dial belongs to no campaign, so another campaign's private script must
    // not follow the agent onto it.
    expect(array_column($scripts, 'content'))->toBe(['Thanks for your time.']);
});

it('never carries another client script onto this agent screen', function () {
    $mine = Tenant::factory()->create();
    $theirs = Tenant::factory()->create();
    $agent = clientUserWithRole($mine, RoleName::Agent->value);
    $this->actingAs($agent);

    // Kept and asserted so this test cannot pass by the other client's row never having
    // been written — the failure shape that makes a tenant-wall test look green forever.
    $theirScript = TenantContext::run($theirs->id, fn () => Script::factory()
        ->type(ScriptType::Opening)
        ->create(['content' => 'Good morning, Other Client here.']));

    expect($theirScript->tenant_id)->toBe($theirs->id);

    $scripts = TenantContext::run($mine->id, function (): array {
        Script::factory()->type(ScriptType::Opening)->create(['content' => 'Thank you for calling.']);

        return consoleOnCampaign(null)->callHistory()['scripts'];
    });

    expect(array_column($scripts, 'content'))->toBe(['Thank you for calling.']);
});

// The markup half. `callHistory()` answering with the right scripts is worth nothing if
// the block that draws them has been deleted or its state renamed — the exact failure no
// data test can see, and one this console has shipped twice.
it('carries the script panel markup bound to the sent scripts', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $this->actingAs($agent);
    TenantContext::applyWebRequest($tenant->id, crossTenant: false);

    $html = Livewire::test(AgentConsole::class)->html();

    expect($html)->toContain('script in history.scripts')
        // Tabs, not a scrolling list — the panel is bounded at three by ScriptType.
        ->and($html)->toContain('scriptTab = script.type')
        // Plain text, because the admin form stores a Textarea and not a rich editor.
        ->and($html)->toContain('x-text="script.content"')
        // The leader's own line breaks survive, and the measure stays readable now the
        // card is 1280px wide. Constrain the text, not the page.
        ->and($html)->toContain('whitespace-pre-wrap')
        ->and($html)->toContain('max-w-prose');
});
