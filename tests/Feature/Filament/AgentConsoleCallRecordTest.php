<?php

use App\Enums\CallDirection;
use App\Enums\CallOutcome;
use App\Enums\LeadStatus;
use App\Enums\RoleName;
use App\Filament\Pages\AgentConsole;
use App\Models\ActivityLog;
use App\Models\Call;
use App\Models\CallHandoff;
use App\Models\Campaign;
use App\Models\Disposition;
use App\Models\DncEntry;
use App\Models\Lead;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::forget();
});

/**
 * B3 CP-B3-1 — the web wrap-up writes the `calls` row (D2). outcome is sourced
 * from the disposition's is_contact (S39), direction is server-set, and the lead
 * update + call.wrapped_up audit are side-effects of the row. The page methods are
 * driven directly (the path the browser runs over $wire) in the agent's context.
 */
const OUTBOUND_CALLER_ID = '18001234567';

/**
 * A served campaign + one disposition + a callable lead. is_contact decides the
 * derived outcome.
 *
 * @return array{0: int, 1: int, 2: int}
 */
function seedCallCampaign(Tenant $tenant, string $code, bool $isContact, string $phone = '9991234567'): array
{
    return TenantContext::run($tenant->id, function () use ($code, $isContact, $phone): array {
        $campaign = Campaign::factory()->create(['is_active' => true]);
        $disposition = Disposition::factory()->forCampaign($campaign)->create([
            'code' => $code,
            'label' => $code,
            'is_contact' => $isContact,
        ]);
        $lead = Lead::factory()->forCampaign($campaign)->status(LeadStatus::New)->create([
            'phone' => $phone,
            'attempts' => 0,
        ]);

        return [$campaign->id, $lead->id, $disposition->id];
    });
}

function fakeOriginate(): void
{
    config()->set('telephony.agent.endpoint', 'PJSIP/1003');
    config()->set('telephony.outbound.caller_id', OUTBOUND_CALLER_ID);
    Http::fake(['*' => Http::response(['id' => 'agent-leg'])]);
}

it('writes an outbound answered calls row on a contact wrap-up, with lead update + audit as side-effects', function () {
    fakeOriginate();

    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    [$campaignId, $leadId, $dispositionId] = seedCallCampaign($tenant, 'INTERESTED', isContact: true);

    $this->actingAs($agent);

    TenantContext::run($tenant->id, function () use ($campaignId, $dispositionId): void {
        $page = new AgentConsole;
        $page->selectedCampaignId = $campaignId;
        $page->dial();
        $page->saveWrapUp($dispositionId);
    });

    $call = TenantContext::run($tenant->id, fn (): ?Call => Call::query()->latest('id')->first());

    expect($call)->not->toBeNull()
        ->and($call->direction)->toBe(CallDirection::Outbound)
        ->and($call->outcome)->toBe(CallOutcome::Answered)
        ->and($call->lead_id)->toBe($leadId)
        ->and($call->campaign_id)->toBe($campaignId)
        ->and($call->agent_id)->toBe($agent->id)
        ->and($call->disposition_id)->toBe($dispositionId)
        ->and($call->to_number)->toBe('9991234567')
        ->and($call->from_number)->toBe(OUTBOUND_CALLER_ID)
        ->and($call->ended_at)->not->toBeNull()
        ->and($call->tenant_id)->toBe($tenant->id);

    // Side-effects of the row, same transaction: the lead advanced and the
    // compliance call.wrapped_up event was written.
    $lead = TenantContext::run($tenant->id, fn (): ?Lead => Lead::find($leadId));

    expect($lead->last_disposition_id)->toBe($dispositionId)
        ->and($lead->attempts)->toBe(1)
        ->and($lead->status)->toBe(LeadStatus::Contacted);

    $wrapped = TenantContext::run($tenant->id, fn (): ?ActivityLog => ActivityLog::query()
        ->where('log_name', 'call')->where('event', 'wrapped_up')->latest('id')->first());

    expect($wrapped)->not->toBeNull()
        ->and($wrapped->subject_id)->toBe($leadId);
});

it('derives outcome no_answer when the disposition is not a contact', function () {
    fakeOriginate();

    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    [$campaignId, , $dispositionId] = seedCallCampaign($tenant, 'NO_ANSWER', isContact: false);

    $this->actingAs($agent);

    TenantContext::run($tenant->id, function () use ($campaignId, $dispositionId): void {
        $page = new AgentConsole;
        $page->selectedCampaignId = $campaignId;
        $page->dial();
        $page->saveWrapUp($dispositionId);
    });

    $call = TenantContext::run($tenant->id, fn (): ?Call => Call::query()->latest('id')->first());

    expect($call->outcome)->toBe(CallOutcome::NoAnswer)
        ->and($call->direction)->toBe(CallDirection::Outbound);
});

it('writes a lead-less ad-hoc row with a null outcome', function () {
    fakeOriginate();

    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $this->actingAs($agent);

    TenantContext::run($tenant->id, function (): void {
        $page = new AgentConsole;
        $page->dialAdhoc('9990008888'); // sets direction outbound + party, no lead
        $page->completeUnmatched();
    });

    $call = TenantContext::run($tenant->id, fn (): ?Call => Call::query()->latest('id')->first());

    expect($call)->not->toBeNull()
        ->and($call->lead_id)->toBeNull()
        ->and($call->campaign_id)->toBeNull()
        ->and($call->disposition_id)->toBeNull()
        ->and($call->outcome)->toBeNull()
        ->and($call->direction)->toBe(CallDirection::Outbound)
        ->and($call->to_number)->toBe('9990008888')
        ->and($call->agent_id)->toBe($agent->id);
});

it('writes an inbound row when the call arrived (no dial)', function () {
    config()->set('telephony.outbound.caller_id', OUTBOUND_CALLER_ID);

    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    [, $leadId, $dispositionId] = seedCallCampaign($tenant, 'INTERESTED', isContact: true, phone: '9995550000');

    $this->actingAs($agent);

    TenantContext::run($tenant->id, function () use ($dispositionId): void {
        $page = new AgentConsole;
        $page->lookupLead('9995550000'); // an incoming caller matched to a lead
        $page->saveWrapUp($dispositionId);
    });

    $call = TenantContext::run($tenant->id, fn (): ?Call => Call::query()->latest('id')->first());

    expect($call->direction)->toBe(CallDirection::Inbound)
        ->and($call->outcome)->toBe(CallOutcome::Answered)
        ->and($call->lead_id)->toBe($leadId)
        ->and($call->from_number)->toBe('9995550000') // the caller
        ->and($call->to_number)->toBeNull();           // no DID configured in v1
});

it('mints a uuid correlation_id at dial and stamps it on the outbound row (CP-B3-2 D3)', function () {
    fakeOriginate();

    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    [$campaignId, , $dispositionId] = seedCallCampaign($tenant, 'INTERESTED', isContact: true);

    $this->actingAs($agent);

    TenantContext::run($tenant->id, function () use ($campaignId, $dispositionId): void {
        $page = new AgentConsole;
        $page->selectedCampaignId = $campaignId;
        $page->dial();
        $page->saveWrapUp($dispositionId);
    });

    $call = TenantContext::run($tenant->id, fn (): ?Call => Call::query()->latest('id')->first());

    // The tracking number is our own UUID — the seam the queued RecordingReady
    // listener matches on to attach the recording (no fuzzy time/number matching).
    expect($call->correlation_id)->not->toBeNull()
        ->and(Str::isUuid($call->correlation_id))->toBeTrue();
});

it('leaves correlation_id null on an inbound row when no handoff note was claimed (the graceful miss, TH-2)', function () {
    config()->set('telephony.outbound.caller_id', OUTBOUND_CALLER_ID);

    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    [, , $dispositionId] = seedCallCampaign($tenant, 'INTERESTED', isContact: true, phone: '9995551111');

    $this->actingAs($agent);

    TenantContext::run($tenant->id, function () use ($dispositionId): void {
        $page = new AgentConsole;
        // No handoff note claimed (listener missed it / process restarted) — the call
        // still records, the row's correlation_id stays null, the recording stays
        // orphaned on disk. Never a crash, never the wrong recording (TH-2).
        $page->lookupLead('9995551111');
        $page->saveWrapUp($dispositionId);
    });

    $call = TenantContext::run($tenant->id, fn (): ?Call => Call::query()->latest('id')->first());

    expect($call->direction)->toBe(CallDirection::Inbound)
        ->and($call->correlation_id)->toBeNull();
});

it('stamps the claimed handoff ticket as correlation_id on an inbound row (TH-4)', function () {
    config()->set('telephony.outbound.caller_id', OUTBOUND_CALLER_ID);

    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    [, , $dispositionId] = seedCallCampaign($tenant, 'INTERESTED', isContact: true, phone: '9995551111');

    // The listener left a handoff note for this agent at ring-time (what beginCall does).
    $ticket = TenantContext::run($tenant->id, fn (): string => CallHandoff::factory()->forAgent($agent)->create()->ticket);

    $this->actingAs($agent);

    TenantContext::run($tenant->id, function () use ($dispositionId): void {
        $page = new AgentConsole;
        $page->claimHandoffTicket();       // the ring-time claim reads the note
        $page->lookupLead('9995551111');   // the lead lookup must NOT clobber it (Option A)
        $page->saveWrapUp($dispositionId);
    });

    $call = TenantContext::run($tenant->id, fn (): ?Call => Call::query()->latest('id')->first());

    expect($call->direction)->toBe(CallDirection::Inbound)
        ->and($call->correlation_id)->toBe($ticket);   // the inbound row now carries the ticket
});

it('writes no calls row when a dial is blocked by the do-not-call list', function () {
    Http::preventStrayRequests();

    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    [$campaignId] = seedCallCampaign($tenant, 'INTERESTED', isContact: true, phone: '9994443333');

    TenantContext::run($tenant->id, fn () => DncEntry::factory()->create(['phone' => '9994443333']));

    $this->actingAs($agent);

    $result = TenantContext::run($tenant->id, function () use ($campaignId): array {
        $page = new AgentConsole;
        $page->selectedCampaignId = $campaignId;

        return $page->dial();
    });

    expect($result['outcome'])->toBe('blocked');

    // D5: a blocked dial places no call — no calls row, audit-only.
    expect(TenantContext::run($tenant->id, fn (): int => Call::query()->count()))->toBe(0);

    $blocked = TenantContext::run($tenant->id, fn (): ?ActivityLog => ActivityLog::query()
        ->where('log_name', 'call')->where('event', 'dnc_blocked')->latest('id')->first());

    expect($blocked)->not->toBeNull();
});
