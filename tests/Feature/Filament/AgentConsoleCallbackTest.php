<?php

use App\Enums\CallbackStatus;
use App\Enums\LeadStatus;
use App\Enums\RoleName;
use App\Filament\Pages\AgentConsole;
use App\Models\ActivityLog;
use App\Models\Callback;
use App\Models\Campaign;
use App\Models\Disposition;
use App\Models\DncEntry;
use App\Models\Lead;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::forget();
});

/**
 * B-outbound M4 (CP-O4 / PR2) Phase 2 — capturing a callback at wrap-up. Picking
 * the CALLBACK-coded disposition creates a sticky callback row owned by the agent,
 * ON TOP of the normal lead write (CALLBACK is a contact). The page methods are
 * driven directly (the path the browser runs over $wire) inside the agent's
 * tenant context.
 */

/**
 * Mint a campaign with a CALLBACK disposition + a served lead, returning their
 * ids. The agent must be acting + in tenant context around the call.
 *
 * @return array{0: int, 1: int, 2: int}
 */
function seedCallbackCampaign(Tenant $tenant): array
{
    return TenantContext::run($tenant->id, function (): array {
        $campaign = Campaign::factory()->create(['is_active' => true]);
        $callback = Disposition::factory()->forCampaign($campaign)->create([
            'code' => Disposition::CALLBACK_CODE,
            'label' => 'Callback requested',
            'is_contact' => true,
        ]);
        $lead = Lead::factory()->forCampaign($campaign)->status(LeadStatus::New)->create([
            'phone' => '9991234567',
            'attempts' => 0,
        ]);

        return [$campaign->id, $lead->id, $callback->id];
    });
}

it('creates a sticky callback owned by the agent and still runs the normal lead write', function () {
    config()->set('telephony.agent.endpoint', 'PJSIP/1003');
    Http::fake(['*' => Http::response(['id' => 'agent-leg'])]);

    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    [$campaignId, $leadId, $callbackDispositionId] = seedCallbackCampaign($tenant);

    $this->actingAs($agent);

    TenantContext::run($tenant->id, function () use ($campaignId, $callbackDispositionId): void {
        $page = new AgentConsole;
        $page->selectedCampaignId = $campaignId;
        $page->dial(); // outbound stash at dial
        $page->saveWrapUp($callbackDispositionId, '2026-12-01T17:30', 'prefers evenings');
    });

    $lead = TenantContext::run($tenant->id, fn (): ?Lead => Lead::find($leadId));

    // The normal write still happens — CALLBACK is a contact: attempts +1,
    // New -> Contacted, the outcome stuck.
    expect($lead->last_disposition_id)->toBe($callbackDispositionId)
        ->and($lead->attempts)->toBe(1)
        ->and($lead->status)->toBe(LeadStatus::Contacted);

    // The callback row is created on top, sticky to the agent, pending and due-able.
    $callback = TenantContext::run($tenant->id, fn (): ?Callback => Callback::query()->latest('id')->first());

    expect($callback)->not->toBeNull()
        ->and($callback->lead_id)->toBe($leadId)
        ->and($callback->campaign_id)->toBe($campaignId)
        ->and($callback->owner_agent_id)->toBe($agent->id)
        ->and($callback->status)->toBe(CallbackStatus::Pending)
        ->and($callback->notes)->toBe('prefers evenings')
        ->and($callback->scheduled_at->format('Y-m-d H:i'))->toBe('2026-12-01 17:30');
});

it('records a non-callback outcome without creating a callback row', function () {
    config()->set('telephony.agent.endpoint', 'PJSIP/1003');
    Http::fake(['*' => Http::response(['id' => 'agent-leg'])]);

    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    [$campaignId, $leadId, $interestedId] = TenantContext::run($tenant->id, function (): array {
        $campaign = Campaign::factory()->create(['is_active' => true]);
        $interested = Disposition::factory()->forCampaign($campaign)->create([
            'code' => 'INTERESTED',
            'label' => 'Interested',
            'is_contact' => true,
        ]);
        $lead = Lead::factory()->forCampaign($campaign)->status(LeadStatus::New)->create(['attempts' => 0]);

        return [$campaign->id, $lead->id, $interested->id];
    });

    $this->actingAs($agent);

    TenantContext::run($tenant->id, function () use ($campaignId, $interestedId): void {
        $page = new AgentConsole;
        $page->selectedCampaignId = $campaignId;
        $page->dial();
        $page->saveWrapUp($interestedId); // no schedule passed — and none needed
    });

    $lead = TenantContext::run($tenant->id, fn (): ?Lead => Lead::find($leadId));

    expect($lead->last_disposition_id)->toBe($interestedId)
        ->and($lead->attempts)->toBe(1)
        ->and(TenantContext::run($tenant->id, fn (): int => Callback::query()->count()))->toBe(0);
});

it('rejects a callback with no scheduled time and writes nothing', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    [$campaignId, $leadId, $callbackDispositionId] = seedCallbackCampaign($tenant);

    $this->actingAs($agent);

    TenantContext::run($tenant->id, function () use ($campaignId, $leadId, $callbackDispositionId): void {
        $page = new AgentConsole;
        $page->matchedLeadId = $leadId;
        $page->matchedCampaignId = $campaignId;

        expect(fn () => $page->saveWrapUp($callbackDispositionId, null))
            ->toThrow(HttpException::class);
    });

    // Validation runs before the writes — the lead is untouched, no callback exists.
    $lead = TenantContext::run($tenant->id, fn (): ?Lead => Lead::find($leadId));

    expect($lead->attempts)->toBe(0)
        ->and($lead->status)->toBe(LeadStatus::New)
        ->and($lead->last_disposition_id)->toBeNull()
        ->and(TenantContext::run($tenant->id, fn (): int => Callback::query()->count()))->toBe(0);
});

it('rejects a callback scheduled in the past', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    [$campaignId, $leadId, $callbackDispositionId] = seedCallbackCampaign($tenant);

    $this->actingAs($agent);

    TenantContext::run($tenant->id, function () use ($campaignId, $leadId, $callbackDispositionId): void {
        $page = new AgentConsole;
        $page->matchedLeadId = $leadId;
        $page->matchedCampaignId = $campaignId;

        expect(fn () => $page->saveWrapUp($callbackDispositionId, '2020-01-01T09:00'))
            ->toThrow(HttpException::class);
    });

    expect(TenantContext::run($tenant->id, fn (): int => Callback::query()->count()))->toBe(0);
});

it('reports which dispositions schedule a callback, walled to the tenant', function () {
    $clientA = Tenant::factory()->create();
    $clientB = Tenant::factory()->create();

    [$aCampaignId, $aCallbackId, $aInterestedId] = TenantContext::run($clientA->id, function (): array {
        $campaign = Campaign::factory()->create(['is_active' => true]);
        $callback = Disposition::factory()->forCampaign($campaign)->create(['code' => Disposition::CALLBACK_CODE]);
        $interested = Disposition::factory()->forCampaign($campaign)->create(['code' => 'INTERESTED']);

        return [$campaign->id, $callback->id, $interested->id];
    });

    // Client B also has a CALLBACK disposition — it must never leak into A's set.
    TenantContext::run($clientB->id, fn () => Disposition::factory()->create(['code' => Disposition::CALLBACK_CODE]));

    $ids = TenantContext::run($clientA->id, function () use ($aCampaignId): array {
        $page = new AgentConsole;
        $page->matchedCampaignId = $aCampaignId;

        return $page->callbackDispositionIds();
    });

    expect($ids)->toBe([$aCallbackId])               // only A's CALLBACK id
        ->and($ids)->not->toContain($aInterestedId); // the non-callback outcome is excluded
});

/**
 * Phase 3 — the due-list, dialing a callback, and the serving exclusion.
 */
it('lists only the agent\'s own due, pending callbacks (cross-agent + cross-tenant walls)', function () {
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();
    $agentA = clientUserWithRole($tenant, RoleName::Agent->value);
    $agentB = clientUserWithRole($tenant, RoleName::Agent->value);

    $dueOwnedId = TenantContext::run($tenant->id, function () use ($agentA, $agentB): int {
        $due = Callback::factory()->forAgent($agentA)->due()->create();                          // shows
        Callback::factory()->forAgent($agentA)->notYetDue()->create();                           // future — hidden
        Callback::factory()->forAgent($agentA)->due()->status(CallbackStatus::Done)->create();   // done — hidden
        Callback::factory()->forAgent($agentB)->due()->create();                                 // other agent — hidden

        return $due->id;
    });

    // Same owner id, different tenant — RLS must still hide it from A's list.
    TenantContext::run($otherTenant->id, fn () => Callback::factory()->forAgent($agentA)->due()->create());

    $this->actingAs($agentA);

    $due = TenantContext::run($tenant->id, fn (): array => (new AgentConsole)->dueCallbacks());

    expect($due)->toHaveCount(1)
        ->and($due[0]['id'])->toBe($dueOwnedId);
});

it('dials a specific due callback, stashes its lead, originates, and marks it done', function () {
    config()->set('telephony.agent.endpoint', 'PJSIP/1003');
    Http::fake(['*' => Http::response(['id' => 'agent-leg'])]);

    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    [$callbackId, $leadId, $campaignId] = TenantContext::run($tenant->id, function () use ($agent): array {
        $campaign = Campaign::factory()->create(['is_active' => true]);
        $lead = Lead::factory()->forCampaign($campaign)->create(['phone' => '9991234567']);
        $callback = Callback::factory()->forLead($lead)->forAgent($agent)->due()->create();

        return [$callback->id, $lead->id, $campaign->id];
    });

    $this->actingAs($agent);

    [$result, $page] = TenantContext::run($tenant->id, function () use ($callbackId): array {
        $page = new AgentConsole;
        $result = $page->dialCallback($callbackId);

        return [$result, $page];
    });

    expect($result['outcome'])->toBe('dialed')
        ->and($result['lead']['id'])->toBe($leadId)
        ->and($page->matchedLeadId)->toBe($leadId)         // stashed like a served lead
        ->and($page->matchedCampaignId)->toBe($campaignId);

    // The specific lead's number is originated on the agent leg (the C-transport).
    Http::assertSent(function (Request $request): bool {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $params);

        return str_contains($request->url(), '/ari/channels?')
            && ($params['appArgs'] ?? null) === 'agent,9991234567';
    });

    // Dialing consumed the callback — it is now done (off the due-list).
    $callback = TenantContext::run($tenant->id, fn () => Callback::find($callbackId));
    expect($callback->status)->toBe(CallbackStatus::Done);
});

it('refuses to dial another agent\'s callback and never originates (cross-agent wall)', function () {
    Http::preventStrayRequests();

    $tenant = Tenant::factory()->create();
    $owner = clientUserWithRole($tenant, RoleName::Agent->value);
    $other = clientUserWithRole($tenant, RoleName::Agent->value);

    $callbackId = TenantContext::run($tenant->id, fn (): int => Callback::factory()->forAgent($owner)->due()->create()->id);

    $this->actingAs($other);

    $result = TenantContext::run($tenant->id, fn (): array => (new AgentConsole)->dialCallback($callbackId));

    expect($result['outcome'])->toBe('none');

    // The owner's callback is untouched — still pending.
    $callback = TenantContext::run($tenant->id, fn () => Callback::find($callbackId));
    expect($callback->status)->toBe(CallbackStatus::Pending);
});

it('blocks a callback whose number is now on the do-not-call list: done, lead closed, audited, never dialed', function () {
    Http::preventStrayRequests();

    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    [$callbackId, $leadId] = TenantContext::run($tenant->id, function () use ($agent): array {
        $campaign = Campaign::factory()->create(['is_active' => true]);
        $lead = Lead::factory()->forCampaign($campaign)->status(LeadStatus::Contacted)->create(['phone' => '9991234567']);
        $callback = Callback::factory()->forLead($lead)->forAgent($agent)->due()->create();
        DncEntry::factory()->create(['phone' => '9991234567']);

        return [$callback->id, $lead->id];
    });

    $this->actingAs($agent);

    $result = TenantContext::run($tenant->id, fn (): array => (new AgentConsole)->dialCallback($callbackId));

    expect($result['outcome'])->toBe('blocked')
        ->and($result['phone'])->toBe('9991234567');

    $callback = TenantContext::run($tenant->id, fn () => Callback::find($callbackId));
    $lead = TenantContext::run($tenant->id, fn () => Lead::find($leadId));

    expect($callback->status)->toBe(CallbackStatus::Done)   // consumed
        ->and($lead->status)->toBe(LeadStatus::Closed);     // compliance hard-stop

    $blocked = TenantContext::run($tenant->id, fn (): ?ActivityLog => ActivityLog::query()
        ->where('log_name', 'call')->where('event', 'dnc_blocked')->latest('id')->first());

    expect($blocked)->not->toBeNull()
        ->and($blocked->subject_id)->toBe($leadId);
});

it('parks a lead with a pending callback out of the preview, returning it once the callback is done', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    [$campaignId, $leadId] = TenantContext::run($tenant->id, function (): array {
        $campaign = Campaign::factory()->create(['is_active' => true]);
        $lead = Lead::factory()->forCampaign($campaign)->status(LeadStatus::New)->create(['attempts' => 0]);

        return [$campaign->id, $lead->id];
    });

    $serve = fn (): ?array => TenantContext::run($tenant->id, function () use ($campaignId): ?array {
        $page = new AgentConsole;
        $page->selectedCampaignId = $campaignId;

        return $page->servedLead();
    });

    // No callback yet — the lead is served.
    expect($serve()['id'])->toBe($leadId);

    // A pending callback (even a future one) parks the lead out of the preview.
    $callbackId = TenantContext::run($tenant->id, function () use ($leadId, $agent): int {
        return Callback::factory()->forLead(Lead::find($leadId))->forAgent($agent)->notYetDue()->create()->id;
    });

    expect($serve())->toBeNull();

    // Once the callback is done, the lead returns to the pool.
    TenantContext::run($tenant->id, fn () => Callback::find($callbackId)->update(['status' => CallbackStatus::Done]));

    expect($serve()['id'])->toBe($leadId);
});
