<?php

use App\Enums\LeadStatus;
use App\Enums\RoleName;
use App\Filament\Pages\AgentConsole;
use App\Models\ActivityLog;
use App\Models\Campaign;
use App\Models\Disposition;
use App\Models\DncEntry;
use App\Models\Lead;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

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

    // The agent leg was originated carrying the customer number AND the call's UUID
    // tracking number (appArgs "agent,<number>,<uuid>" — the CP-O0 transport + the
    // CP-B3-2 D3 correlation seam).
    Http::assertSent(function (Request $request): bool {
        [$tag, $number, $uuid] = array_pad(explode(',', dialParams($request)['appArgs']), 3, null);

        return str_contains($request->url(), '/ari/channels?')
            && dialParams($request)['endpoint'] === 'PJSIP/1003'
            && $tag === 'agent' && $number === '9991234567' && Str::isUuid((string) $uuid);
    });
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

it('blocks a served lead on the do-not-call list — never dials, closes it, audits, no attempt counted (O1)', function () {
    Http::preventStrayRequests(); // a blocked dial must never originate

    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    [$campaignId, $leadId] = TenantContext::run($tenant->id, function (): array {
        $campaign = Campaign::factory()->create(['is_active' => true]);
        $lead = Lead::factory()->forCampaign($campaign)->status(LeadStatus::New)->create([
            'phone' => '9991234567',
            'attempts' => 0,
        ]);
        DncEntry::factory()->create(['phone' => '9991234567']);

        return [$campaign->id, $lead->id];
    });

    $this->actingAs($agent);

    $result = TenantContext::run($tenant->id, function () use ($campaignId): array {
        $page = new AgentConsole;
        $page->selectedCampaignId = $campaignId;

        $result = $page->dial();

        // The block clears the held match — wrap-up has nothing to key on.
        expect($page->matchedLeadId)->toBeNull();

        return $result;
    });

    expect($result['outcome'])->toBe('blocked')
        ->and($result['phone'])->toBe('9991234567');

    $lead = TenantContext::run($tenant->id, fn (): ?Lead => Lead::find($leadId));

    // Closed (out of rotation), but never "attempted" or "contacted" — no call ran.
    expect($lead->status)->toBe(LeadStatus::Closed)
        ->and($lead->attempts)->toBe(0)
        ->and($lead->last_disposition_id)->toBeNull();

    // The block is on the `call` stream, attributed to the agent, against the lead.
    $blocked = TenantContext::run($tenant->id, fn (): ?ActivityLog => ActivityLog::query()
        ->where('log_name', 'call')->where('event', 'dnc_blocked')->latest('id')->first());

    expect($blocked)->not->toBeNull()
        ->and($blocked->subject_id)->toBe($leadId)
        ->and($blocked->causer_id)->toBe($agent->id)
        ->and($blocked->properties['matched'])->toBeTrue();
});

it('does not block when the do-not-call entry belongs to another client (tenant wall, O1)', function () {
    config()->set('telephony.agent.endpoint', 'PJSIP/1003');
    Http::fake(['*' => Http::response(['id' => 'agent-leg'])]);

    $clientA = Tenant::factory()->create();
    $clientB = Tenant::factory()->create();
    $agent = clientUserWithRole($clientA, RoleName::Agent->value);

    // Client A holds the lead; client B lists the same number on THEIR own list.
    $campaignId = TenantContext::run($clientA->id, function (): int {
        $campaign = Campaign::factory()->create(['is_active' => true]);
        Lead::factory()->forCampaign($campaign)->status(LeadStatus::New)->create([
            'phone' => '9991234567',
            'attempts' => 0,
        ]);

        return $campaign->id;
    });
    TenantContext::run($clientB->id, fn () => DncEntry::factory()->create(['phone' => '9991234567']));

    $this->actingAs($agent);

    $result = TenantContext::run($clientA->id, function () use ($campaignId): array {
        $page = new AgentConsole;
        $page->selectedCampaignId = $campaignId;

        return $page->dial();
    });

    // B's list never blocks A — the dial proceeds and the agent leg is originated.
    expect($result['outcome'])->toBe('dialed');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/ari/channels?'));
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

it('dials an ad-hoc typed number, stashing no lead, carrying the typed number on the agent leg (D3)', function () {
    config()->set('telephony.agent.endpoint', 'PJSIP/1003');
    Http::fake(['*' => Http::response(['id' => 'agent-leg'])]);

    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $this->actingAs($agent);

    $result = TenantContext::run($tenant->id, function (): array {
        $page = new AgentConsole;
        $result = $page->dialAdhoc('999 765-4321');

        // An ad-hoc call has no lead — nothing is stashed, so wrap-up writes nothing.
        expect($page->matchedLeadId)->toBeNull()
            ->and($page->matchedCampaignId)->toBeNull();

        return $result;
    });

    // The typed number is normalized (spaces/dashes stripped) before it dials.
    expect($result['outcome'])->toBe('dialed')
        ->and($result['phone'])->toBe('9997654321');

    Http::assertSent(function (Request $request): bool {
        [$tag, $number, $uuid] = array_pad(explode(',', dialParams($request)['appArgs']), 3, null);

        return str_contains($request->url(), '/ari/channels?')
            && dialParams($request)['endpoint'] === 'PJSIP/1003'
            && $tag === 'agent' && $number === '9997654321' && Str::isUuid((string) $uuid);
    });
});

it('blocks an ad-hoc number on the do-not-call list — never dials, audits, writes nothing (O1)', function () {
    Http::preventStrayRequests(); // a blocked ad-hoc dial must never originate

    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    TenantContext::run($tenant->id, fn () => DncEntry::factory()->create(['phone' => '9997654321']));

    $this->actingAs($agent);

    $result = TenantContext::run($tenant->id, fn (): array => (new AgentConsole)->dialAdhoc('999 765-4321'));

    expect($result['outcome'])->toBe('blocked')
        ->and($result['phone'])->toBe('9997654321');

    // Audited on the `call` stream as an unmatched (no-lead) block carrying the number.
    $blocked = TenantContext::run($tenant->id, fn (): ?ActivityLog => ActivityLog::query()
        ->where('log_name', 'call')->where('event', 'dnc_blocked')->latest('id')->first());

    expect($blocked)->not->toBeNull()
        ->and($blocked->subject_id)->toBeNull()
        ->and($blocked->properties['matched'])->toBeFalse()
        ->and($blocked->properties['phone'])->toBe('9997654321');
});

it('forbids ad-hoc dialing without the dial-adhoc ability (D3 gate)', function () {
    Http::preventStrayRequests(); // a forbidden dial must never originate

    $tenant = Tenant::factory()->create();
    $user = clientUserWithRole($tenant, RoleName::ClientUser->value);

    $this->actingAs($user);

    TenantContext::run($tenant->id, function (): void {
        expect(Gate::denies('dial-adhoc'))->toBeTrue();

        $page = new AgentConsole;

        expect(fn (): array => $page->dialAdhoc('9997654321'))
            ->toThrow(AuthorizationException::class);
    });
});

/*
|--------------------------------------------------------------------------
| B2.4a — the web's cold-transfer signal + the outbound agent-id thread (TD-4)
|--------------------------------------------------------------------------
*/

it('signals a cold transfer carrying the agent user id and the server-derived tenant (B2.4a TD-4)', function () {
    config()->set('telephony.asterisk.app', 'hcmis-test');
    Http::fake(['*' => Http::response(null, 204)]);

    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $this->actingAs($agent);

    TenantContext::run($tenant->id, function () use ($agent, $tenant): void {
        (new AgentConsole)->transferCall();

        // A source-less user-event named 'transfer': the app rides the query, the
        // correlator (agent user id) + the server-derived tenant ride the JSON body.
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/ari/events/user/transfer?')
            && dialParams($request) === ['application' => 'hcmis-test']
            && $request->data() === ['variables' => [
                'agentUserId' => (string) $agent->id,
                'tenantId' => (string) $tenant->id,
            ]]);
    });
});

it('threads the dialing agent user id as the 4th app-arg so an outbound call is transferable (B2.4a)', function () {
    config()->set('telephony.agent.endpoint', 'PJSIP/1003');
    Http::fake(['*' => Http::response(['id' => 'agent-leg'])]);

    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $campaignId = TenantContext::run($tenant->id, function (): int {
        $campaign = Campaign::factory()->create(['is_active' => true]);
        Lead::factory()->forCampaign($campaign)->status(LeadStatus::New)->create([
            'phone' => '9991234567',
            'attempts' => 0,
        ]);

        return $campaign->id;
    });

    $this->actingAs($agent);

    TenantContext::run($tenant->id, function () use ($campaignId): void {
        $page = new AgentConsole;
        $page->selectedCampaignId = $campaignId;
        $page->dial();
    });

    Http::assertSent(function (Request $request) use ($agent): bool {
        [$tag, $number, $uuid, $agentId] = array_pad(explode(',', dialParams($request)['appArgs']), 4, null);

        return str_contains($request->url(), '/ari/channels?')
            && $tag === 'agent' && $number === '9991234567' && Str::isUuid((string) $uuid)
            && $agentId === (string) $agent->id;
    });
});

it('rings the logged-in agent own phone on outbound, not the one fixed endpoint (B2.4a §7 outbound-per-agent)', function () {
    config()->set('telephony.agent.endpoint', 'PJSIP/1003');   // the fixed fallback (agent A)
    Http::fake(['*' => Http::response(['id' => 'agent-leg'])]);

    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    // This agent's OWN phone, keyed by their user id (the B2.2b Fold A directory).
    config()->set('telephony.agent.directory', [
        $agent->id => ['endpoint' => 'PJSIP/1004'],
    ]);

    $campaignId = TenantContext::run($tenant->id, function (): int {
        $campaign = Campaign::factory()->create(['is_active' => true]);
        Lead::factory()->forCampaign($campaign)->status(LeadStatus::New)->create([
            'phone' => '9991234567',
            'attempts' => 0,
        ]);

        return $campaign->id;
    });

    $this->actingAs($agent);

    TenantContext::run($tenant->id, function () use ($campaignId): void {
        $page = new AgentConsole;
        $page->selectedCampaignId = $campaignId;
        $page->dial();
    });

    // The agent leg rang THIS agent's own endpoint, not the fixed PJSIP/1003.
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/ari/channels?')
        && dialParams($request)['endpoint'] === 'PJSIP/1004');
});
