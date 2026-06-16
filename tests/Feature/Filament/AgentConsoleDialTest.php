<?php

use App\Enums\LeadStatus;
use App\Enums\RoleName;
use App\Filament\Pages\AgentConsole;
use App\Models\ActivityLog;
use App\Models\Campaign;
use App\Models\Disposition;
use App\Models\Lead;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::forget();
});

/**
 * B-outbound M1+M2 (CP-O1) — the served-lead preview + agent-first origination.
 * The page methods are driven directly (the path the browser runs over $wire)
 * inside the agent's tenant context. Http::fake keeps the ARI originate off the
 * network so we can assert exactly what the agent leg carries.
 */

/**
 * ARI commands carry their parameters in the query string.
 *
 * @return array<string, string>
 */
function dialParams(Request $request): array
{
    parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $params);

    return $params;
}

it('serves the next callable lead, stashes its locked ids at dial, and originates the agent leg carrying the customer number', function () {
    config()->set('telephony.agent.endpoint', 'PJSIP/1003');
    Http::fake(['*' => Http::response(['id' => 'agent-leg'])]);

    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    [$campaignId, $leadId] = TenantContext::run($tenant->id, function (): array {
        $campaign = Campaign::factory()->create(['is_active' => true]);
        $lead = Lead::factory()->forCampaign($campaign)->status(LeadStatus::New)->create([
            'phone' => '9991234567',
            'attempts' => 0,
        ]);

        return [$campaign->id, $lead->id];
    });

    $this->actingAs($agent);

    $page = TenantContext::run($tenant->id, function () use ($campaignId): AgentConsole {
        $page = new AgentConsole;
        $page->selectedCampaignId = $campaignId;

        // The preview shows the lead but stashes nothing (the inverse of inbound).
        expect($page->servedLead()['id'])->toBe($page->servedLead()['id']);
        expect($page->matchedLeadId)->toBeNull();

        $page->dial();

        return $page;
    });

    // Dial stashed the locked ids server-side (the wrap-up keys off these).
    expect($page->matchedLeadId)->toBe($leadId)
        ->and($page->matchedCampaignId)->toBe($campaignId);

    // The agent leg was originated carrying the customer number as the tag detail
    // (appArgs "agent,<number>" — the CP-O0 transport).
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/ari/channels?')
        && dialParams($request)['endpoint'] === 'PJSIP/1003'
        && dialParams($request)['appArgs'] === 'agent,9991234567');
});

it('writes a no-answer (non-contact) outcome on an unanswered outbound — attempts +1, status forward, audited (CP-O2 / D5)', function () {
    config()->set('telephony.agent.endpoint', 'PJSIP/1003');
    Http::fake(['*' => Http::response(['id' => 'agent-leg'])]);

    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    [$campaignId, $leadId, $noAnswerId] = TenantContext::run($tenant->id, function (): array {
        $campaign = Campaign::factory()->create(['is_active' => true]);
        // NO_ANSWER is seeded non-contact in every template; here we mint the same
        // shape so the picker accepts it for this campaign.
        $noAnswer = Disposition::factory()->forCampaign($campaign)->create([
            'is_contact' => false,
            'label' => 'No answer',
        ]);
        $lead = Lead::factory()->forCampaign($campaign)->status(LeadStatus::New)->create([
            'phone' => '9991234567',
            'attempts' => 0,
        ]);

        return [$campaign->id, $lead->id, $noAnswer->id];
    });

    $this->actingAs($agent);

    TenantContext::run($tenant->id, function () use ($campaignId, $noAnswerId): void {
        $page = new AgentConsole;
        $page->selectedCampaignId = $campaignId;
        $page->dial();                  // outbound stash at dial; the customer never answers
        $page->saveWrapUp($noAnswerId); // the agent picks "No answer" in wrap-up
    });

    $lead = TenantContext::run($tenant->id, fn (): ?Lead => Lead::find($leadId));

    // The unchanged saveWrapUp / AdvanceLeadStatus carry a non-contact outcome:
    // New + non-contact -> In Progress, the attempt is counted, the outcome stuck.
    expect($lead->last_disposition_id)->toBe($noAnswerId)
        ->and($lead->attempts)->toBe(1)
        ->and($lead->status)->toBe(LeadStatus::InProgress);

    // The call-stream audit still fires for an unanswered outbound (matched lead).
    $call = TenantContext::run($tenant->id, fn (): ?ActivityLog => ActivityLog::query()
        ->where('log_name', 'call')->where('event', 'wrapped_up')->latest('id')->first());

    expect($call)->not->toBeNull()
        ->and($call->subject_id)->toBe($leadId)
        ->and($call->causer_id)->toBe($agent->id)
        ->and($call->properties['matched'])->toBeTrue();
});

it('serves fewest-attempts-then-oldest and never serves a closed lead', function () {
    $tenant = Tenant::factory()->create();

    [$campaignId, $fewestId] = TenantContext::run($tenant->id, function (): array {
        $campaign = Campaign::factory()->create(['is_active' => true]);

        // A closed lead with zero attempts must never be served despite sorting first.
        Lead::factory()->forCampaign($campaign)->status(LeadStatus::Closed)->create(['attempts' => 0]);
        // The most-tried open lead sorts last.
        Lead::factory()->forCampaign($campaign)->status(LeadStatus::InProgress)->create(['attempts' => 5]);
        // The least-tried open lead is the one served.
        $fewest = Lead::factory()->forCampaign($campaign)->status(LeadStatus::New)->create(['attempts' => 1]);

        return [$campaign->id, $fewest->id];
    });

    $served = TenantContext::run($tenant->id, function () use ($campaignId): ?array {
        $page = new AgentConsole;
        $page->selectedCampaignId = $campaignId;

        return $page->servedLead();
    });

    expect($served)->not->toBeNull()
        ->and($served['id'])->toBe($fewestId);
});

it('skips a lead to the next without dialing or writing anything', function () {
    Http::preventStrayRequests(); // a skip must never originate

    $tenant = Tenant::factory()->create();

    [$campaignId, $firstId, $secondId] = TenantContext::run($tenant->id, function (): array {
        $campaign = Campaign::factory()->create(['is_active' => true]);
        $first = Lead::factory()->forCampaign($campaign)->create(['attempts' => 0]);
        $second = Lead::factory()->forCampaign($campaign)->create(['attempts' => 1]);

        return [$campaign->id, $first->id, $second->id];
    });

    TenantContext::run($tenant->id, function () use ($campaignId, $firstId, $secondId): void {
        $page = new AgentConsole;
        $page->selectedCampaignId = $campaignId;

        expect($page->servedLead()['id'])->toBe($firstId);

        $page->skip();

        expect($page->servedLead()['id'])->toBe($secondId)
            ->and($page->matchedLeadId)->toBeNull();   // skip stashes nothing
    });
});

it('never serves a lead that belongs to another client (tenant wall)', function () {
    $clientA = Tenant::factory()->create();
    $clientB = Tenant::factory()->create();

    $bCampaignId = TenantContext::run(
        $clientB->id,
        fn (): int => Campaign::factory()->create(['is_active' => true])->id,
    );
    TenantContext::run($clientB->id, fn () => Lead::factory()->create([
        'campaign_id' => $bCampaignId,
    ]));

    // Scoped to client A, selecting B's campaign id serves nothing — RLS walls it.
    $served = TenantContext::run($clientA->id, function () use ($bCampaignId): ?array {
        $page = new AgentConsole;
        $page->selectedCampaignId = $bCampaignId;

        return $page->servedLead();
    });

    expect($served)->toBeNull();
});
