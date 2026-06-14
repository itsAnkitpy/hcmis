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
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

afterEach(function () {
    // The page methods run in the agent's web posture; clear both the GUC marker
    // and the app context so neither leaks onto the next test on this connection
    // (same guard as the access + manual-audit tests).
    TenantContext::resetWebRequest();
    TenantContext::forget();
});

/**
 * B4 CP3 wrap-up write (decision B+C+D). The agent records the outcome of the
 * call they just handled through the real lookup → save seam. These prove the
 * lead row changes, the audit lands, the narrow gate never becomes Leads CRUD,
 * and every tenant / campaign / no-match wall holds.
 *
 * The page methods are driven directly (the lookup → save path the browser runs
 * over $wire) inside the agent's tenant context, with the agent authenticated so
 * the gate and audit causer resolve exactly as they do in a real request.
 */
it('records the disposition, bumps attempts, nudges status forward, and audits the call', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    [$leadId, $dispositionId] = TenantContext::run($tenant->id, function (): array {
        $campaign = Campaign::factory()->create();
        $disposition = Disposition::factory()->forCampaign($campaign)->create([
            'is_contact' => true,
            'label' => 'Interested',
        ]);
        $lead = Lead::factory()->forCampaign($campaign)->status(LeadStatus::New)->create([
            'phone' => '9991234567',
            'attempts' => 0,
        ]);

        return [$lead->id, $disposition->id];
    });

    $this->actingAs($agent);

    TenantContext::run($tenant->id, function () use ($dispositionId): void {
        $page = new AgentConsole;
        $page->lookupLead('9991234567'); // server-holds the matched lead id
        $page->saveWrapUp($dispositionId);
    });

    $lead = TenantContext::run($tenant->id, fn (): ?Lead => Lead::find($leadId));

    expect($lead->last_disposition_id)->toBe($dispositionId)
        ->and($lead->attempts)->toBe(1)
        ->and($lead->status)->toBe(LeadStatus::Contacted); // New + contact -> Contacted

    // The purpose-built call-stream event (decision D): subject = lead, caused by
    // the agent, carrying the disposition and matched=true.
    $call = TenantContext::run($tenant->id, fn (): ?ActivityLog => ActivityLog::query()
        ->where('log_name', 'call')->where('event', 'wrapped_up')->latest('id')->first());

    expect($call)->not->toBeNull()
        ->and($call->subject_id)->toBe($leadId)
        ->and($call->causer_id)->toBe($agent->id)
        ->and($call->properties['matched'])->toBeTrue()
        ->and($call->properties['disposition_label'])->toBe('Interested');

    // The auto lead.updated diff also exists — two rows answering two questions.
    $diff = TenantContext::run($tenant->id, fn (): ?ActivityLog => ActivityLog::query()
        ->where('log_name', 'lead')->where('event', 'updated')
        ->where('subject_id', $leadId)->latest('id')->first());

    expect($diff)->not->toBeNull();
});

it('lets the agent record a call outcome but still denies them lead update (the D-M4-5 wall)', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $lead = TenantContext::run($tenant->id, fn (): Lead => Lead::factory()->forCampaign(Campaign::factory()->create())->create());

    $this->actingAs($agent);

    TenantContext::run($tenant->id, function () use ($agent, $lead): void {
        // The narrow seam: the dedicated ability is granted, but the general
        // Leads update right is NOT — agents have no Leads CRUD.
        expect(Gate::allows('record-call-outcome'))->toBeTrue()
            ->and($agent->can('update', $lead))->toBeFalse();
    });
});

it('never records against a lead in another client (the tenant wall)', function () {
    $clientA = Tenant::factory()->create();
    $clientB = Tenant::factory()->create();
    $agent = clientUserWithRole($clientA, RoleName::Agent->value);

    $bLeadId = TenantContext::run(
        $clientB->id,
        fn (): int => Lead::factory()->forCampaign(Campaign::factory()->create())->create([
            'phone' => '9991234567',
            'attempts' => 0,
        ])->id,
    );

    $this->actingAs($agent);

    TenantContext::run($clientA->id, function (): void {
        $page = new AgentConsole;
        // The number matches only B's lead -> the lookup in A returns null, so no
        // id is server-held; a save (even with any disposition id) writes nothing.
        expect($page->lookupLead('9991234567'))->toBeNull();
        $page->saveWrapUp(999999);
    });

    $bLead = TenantContext::run($clientB->id, fn (): ?Lead => Lead::find($bLeadId));

    expect($bLead->attempts)->toBe(0)
        ->and($bLead->last_disposition_id)->toBeNull();
});

it('rejects a disposition that does not belong to the matched lead campaign', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $foreignDispositionId = TenantContext::run($tenant->id, function (): int {
        $campaignA = Campaign::factory()->create();
        $campaignB = Campaign::factory()->create();
        // Belongs to campaign B only (not tenant-wide) -> invalid for an A lead.
        $foreign = Disposition::factory()->forCampaign($campaignB)->create();
        Lead::factory()->forCampaign($campaignA)->create(['phone' => '9991234567', 'attempts' => 0]);

        return $foreign->id;
    });

    $this->actingAs($agent);

    TenantContext::run($tenant->id, function () use ($foreignDispositionId): void {
        $page = new AgentConsole;
        $page->lookupLead('9991234567'); // matches the campaign-A lead

        try {
            $page->saveWrapUp($foreignDispositionId);
            $this->fail('Expected a 403 for a disposition outside the lead campaign.');
        } catch (HttpException $e) {
            expect($e->getStatusCode())->toBe(403);
        }
    });
});

it('logs the no-match miss without writing to any lead', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $this->actingAs($agent);

    TenantContext::run($tenant->id, function (): void {
        $page = new AgentConsole;
        $page->completeUnmatched(); // no lookup -> nothing server-held
    });

    $call = TenantContext::run($tenant->id, fn (): ?ActivityLog => ActivityLog::query()
        ->where('log_name', 'call')->where('event', 'wrapped_up')->latest('id')->first());

    expect($call)->not->toBeNull()
        ->and($call->subject_id)->toBeNull()
        ->and($call->causer_id)->toBe($agent->id)
        ->and($call->properties['matched'])->toBeFalse();
});
