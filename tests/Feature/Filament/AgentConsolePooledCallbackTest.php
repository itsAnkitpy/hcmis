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
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::forget();
});

/**
 * B2.0 — pooled callbacks. A "call me back" can be captured UNOWNED (owner_agent_id
 * null) so any free agent in the client may grab it, rather than sticky to the one
 * agent who took it. The grab resolves the two-agents-one-row race with a single
 * atomic conditional update; everything after the grab reuses the sticky dial path.
 * The page methods are driven directly (the path the browser runs over $wire),
 * inside the agent's tenant context.
 */

/**
 * Mint a campaign with a CALLBACK disposition + a served lead, returning their ids.
 * Distinct name from the sibling test's seeder (same Pest suite, one namespace).
 *
 * @return array{0: int, 1: int, 2: int}
 */
function seedPooledCallbackCampaign(Tenant $tenant): array
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

it('lists only unowned, pending, due callbacks (cross-agent + cross-tenant walls)', function () {
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $someAgent = clientUserWithRole($tenant, RoleName::Agent->value);

    $pooledDueId = TenantContext::run($tenant->id, function () use ($someAgent): int {
        $pooled = Callback::factory()->due()->create();                               // unowned + due  -> shows
        Callback::factory()->notYetDue()->create();                                   // unowned future -> hidden
        Callback::factory()->due()->status(CallbackStatus::Done)->create();           // unowned done   -> hidden
        Callback::factory()->forAgent($someAgent)->due()->create();                   // owned (sticky) -> hidden

        return $pooled->id;
    });

    // A pooled, due callback in another client must never leak across the RLS wall.
    TenantContext::run($otherTenant->id, fn () => Callback::factory()->due()->create());

    $this->actingAs($agent);

    $pooled = TenantContext::run($tenant->id, fn (): array => (new AgentConsole)->pooledCallbacks());

    expect($pooled)->toHaveCount(1)
        ->and($pooled[0]['id'])->toBe($pooledDueId);
});

it('grabs a pooled callback atomically — exactly one of two agents wins', function () {
    $tenant = Tenant::factory()->create();
    $winner = clientUserWithRole($tenant, RoleName::Agent->value);
    $loser = clientUserWithRole($tenant, RoleName::Agent->value);

    $callbackId = TenantContext::run($tenant->id, fn (): int => Callback::factory()->due()->create()->id);

    // The winner grabs first: the conditional update (owner still null) touches one
    // row. The loser then grabs the same row: owner is no longer null, so the SAME
    // conditional touches zero rows -> 'taken'. One statement = race-proof.
    $this->actingAs($winner);
    $first = TenantContext::run($tenant->id, fn (): array => (new AgentConsole)->claimCallback($callbackId));

    $this->actingAs($loser);
    $second = TenantContext::run($tenant->id, fn (): array => (new AgentConsole)->claimCallback($callbackId));

    expect($first['outcome'])->toBe('claimed')
        ->and($second['outcome'])->toBe('taken');

    // The row ends up owned by the winner only — the loser changed nothing.
    $callback = TenantContext::run($tenant->id, fn () => Callback::find($callbackId));
    expect($callback->owner_agent_id)->toBe($winner->id)
        ->and($callback->status)->toBe(CallbackStatus::Pending);
});

it('moves a grabbed callback out of the pool and into the grabber\'s own due-list', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $callbackId = TenantContext::run($tenant->id, function (): int {
        $lead = Lead::factory()->create(['phone' => '9991234567']);

        return Callback::factory()->forLead($lead)->due()->create()->id;
    });

    $this->actingAs($agent);

    [$pooledBefore, $dueBefore] = TenantContext::run($tenant->id, function (): array {
        $page = new AgentConsole;

        return [$page->pooledCallbacks(), $page->dueCallbacks()];
    });

    expect($pooledBefore)->toHaveCount(1)   // visible in the pool
        ->and($dueBefore)->toHaveCount(0);  // not yet anyone's own

    TenantContext::run($tenant->id, fn () => (new AgentConsole)->claimCallback($callbackId));

    [$pooledAfter, $dueAfter] = TenantContext::run($tenant->id, function (): array {
        $page = new AgentConsole;

        return [$page->pooledCallbacks(), $page->dueCallbacks()];
    });

    expect($pooledAfter)->toHaveCount(0)            // gone from the pool
        ->and($dueAfter)->toHaveCount(1)            // now in the grabber's own list
        ->and($dueAfter[0]['id'])->toBe($callbackId);
});

it('dials a grabbed callback through the existing sticky path', function () {
    config()->set('telephony.agent.endpoint', 'PJSIP/1003');
    Http::fake(['*' => Http::response(['id' => 'agent-leg'])]);

    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    [$callbackId, $leadId] = TenantContext::run($tenant->id, function (): array {
        $lead = Lead::factory()->create(['phone' => '9991234567']);
        $callback = Callback::factory()->forLead($lead)->due()->create();

        return [$callback->id, $lead->id];
    });

    $this->actingAs($agent);

    $result = TenantContext::run($tenant->id, function () use ($callbackId): array {
        $page = new AgentConsole;
        $page->claimCallback($callbackId);   // grab -> now mine

        return $page->dialCallback($callbackId); // dial via the untouched sticky path
    });

    expect($result['outcome'])->toBe('dialed')
        ->and($result['lead']['id'])->toBe($leadId);

    // Dialing consumed it, exactly as a sticky callback would.
    $callback = TenantContext::run($tenant->id, fn () => Callback::find($callbackId));
    expect($callback->status)->toBe(CallbackStatus::Done);
});

it('walls a grab to the agent\'s own client (cross-tenant claim changes nothing)', function () {
    $clientA = Tenant::factory()->create();
    $clientB = Tenant::factory()->create();
    $agentB = clientUserWithRole($clientB, RoleName::Agent->value);

    // A pooled callback belongs to client A.
    $callbackId = TenantContext::run($clientA->id, fn (): int => Callback::factory()->due()->create()->id);

    // An agent of client B tries to grab it from inside their own context.
    $this->actingAs($agentB);
    $result = TenantContext::run($clientB->id, fn (): array => (new AgentConsole)->claimCallback($callbackId));

    expect($result['outcome'])->toBe('taken'); // RLS hid the row -> zero rows updated

    // Client A's callback is untouched — still pooled and pending.
    $callback = TenantContext::run($clientA->id, fn () => Callback::find($callbackId));
    expect($callback->owner_agent_id)->toBeNull()
        ->and($callback->status)->toBe(CallbackStatus::Pending);
});

it('captures a pooled callback (owner null) when the agent chooses "anyone"', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    [$campaignId, $leadId, $callbackDispositionId] = seedPooledCallbackCampaign($tenant);

    $this->actingAs($agent);

    TenantContext::run($tenant->id, function () use ($campaignId, $leadId, $callbackDispositionId): void {
        $page = new AgentConsole;
        $page->matchedLeadId = $leadId;
        $page->matchedCampaignId = $campaignId;
        // 4th arg true = pooled.
        $page->saveWrapUp($callbackDispositionId, '2026-12-01T17:30', 'anyone can take this', true);
    });

    $callback = TenantContext::run($tenant->id, fn (): ?Callback => Callback::query()->latest('id')->first());

    expect($callback)->not->toBeNull()
        ->and($callback->owner_agent_id)->toBeNull()          // unowned = pooled
        ->and($callback->status)->toBe(CallbackStatus::Pending)
        ->and($callback->notes)->toBe('anyone can take this');
});

it('still captures a sticky callback (owner = agent) by default', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    [$campaignId, $leadId, $callbackDispositionId] = seedPooledCallbackCampaign($tenant);

    $this->actingAs($agent);

    TenantContext::run($tenant->id, function () use ($campaignId, $leadId, $callbackDispositionId): void {
        $page = new AgentConsole;
        $page->matchedLeadId = $leadId;
        $page->matchedCampaignId = $campaignId;
        // No 4th arg -> defaults false -> the unbroken sticky v1 behaviour.
        $page->saveWrapUp($callbackDispositionId, '2026-12-01T17:30', 'prefers evenings');
    });

    $callback = TenantContext::run($tenant->id, fn (): ?Callback => Callback::query()->latest('id')->first());

    expect($callback)->not->toBeNull()
        ->and($callback->owner_agent_id)->toBe($agent->id);   // sticky to the wrapping agent
});

it('still applies the do-not-call guard to a grabbed-then-dialed callback', function () {
    Http::preventStrayRequests();

    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    [$callbackId, $leadId] = TenantContext::run($tenant->id, function (): array {
        $lead = Lead::factory()->status(LeadStatus::Contacted)->create(['phone' => '9991234567']);
        $callback = Callback::factory()->forLead($lead)->due()->create();
        DncEntry::factory()->create(['phone' => '9991234567']);

        return [$callback->id, $lead->id];
    });

    $this->actingAs($agent);

    $result = TenantContext::run($tenant->id, function () use ($callbackId): array {
        $page = new AgentConsole;
        $page->claimCallback($callbackId); // grab it

        return $page->dialCallback($callbackId); // the DNC guard fires on dial
    });

    expect($result['outcome'])->toBe('blocked')
        ->and($result['phone'])->toBe('9991234567');

    $callback = TenantContext::run($tenant->id, fn () => Callback::find($callbackId));
    $lead = TenantContext::run($tenant->id, fn () => Lead::find($leadId));

    expect($callback->status)->toBe(CallbackStatus::Done)   // consumed
        ->and($lead->status)->toBe(LeadStatus::Closed);     // compliance hard-stop
});

it('logs a callback.grabbed audit line naming the grabbing agent on a win', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    [$callbackId, $leadId] = TenantContext::run($tenant->id, function (): array {
        $lead = Lead::factory()->create(['phone' => '9991234567']);
        $callback = Callback::factory()->forLead($lead)->due()->create();

        return [$callback->id, $lead->id];
    });

    $this->actingAs($agent);

    TenantContext::run($tenant->id, fn () => (new AgentConsole)->claimCallback($callbackId));

    $grabbed = TenantContext::run($tenant->id, fn (): ?ActivityLog => ActivityLog::query()
        ->where('log_name', 'callback')->where('event', 'grabbed')->latest('id')->first());

    expect($grabbed)->not->toBeNull()
        ->and($grabbed->subject_id)->toBe($callbackId)        // the callback grabbed
        ->and($grabbed->causer_id)->toBe($agent->id)          // who grabbed it
        ->and($grabbed->properties['lead_id'])->toBe($leadId); // names the customer
});

it('writes no grabbed audit line on a lost (already taken) grab', function () {
    $tenant = Tenant::factory()->create();
    $winner = clientUserWithRole($tenant, RoleName::Agent->value);
    $loser = clientUserWithRole($tenant, RoleName::Agent->value);

    $callbackId = TenantContext::run($tenant->id, fn (): int => Callback::factory()->due()->create()->id);

    $this->actingAs($winner);
    TenantContext::run($tenant->id, fn () => (new AgentConsole)->claimCallback($callbackId));

    $this->actingAs($loser);
    TenantContext::run($tenant->id, fn () => (new AgentConsole)->claimCallback($callbackId)); // 0 rows -> taken

    // Exactly one grabbed line exists — the winner's. The loser's no-op touched
    // zero rows and so audited nothing.
    $grabbed = TenantContext::run($tenant->id, fn () => ActivityLog::query()
        ->where('log_name', 'callback')->where('event', 'grabbed')->get());

    expect($grabbed)->toHaveCount(1)
        ->and($grabbed->first()->causer_id)->toBe($winner->id);
});
