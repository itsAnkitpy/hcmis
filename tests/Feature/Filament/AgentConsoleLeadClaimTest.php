<?php

use App\Enums\LeadStatus;
use App\Enums\RoleName;
use App\Filament\Pages\AgentConsole;
use App\Models\Campaign;
use App\Models\Disposition;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    fakeAgentDirectory();                                  // every agent holds a phone
    Http::fake(['*' => Http::response(['id' => 'agent-leg'])]); // no ARI on the network
});

afterEach(function () {
    TenantContext::resetWebRequest();
    TenantContext::forget();
});

/**
 * DIAL-1 slice 1 (DP-3, DP-3a) — the claim.
 *
 * Two agents on one campaign were both handed the SAME lead: `attempts` is only
 * bumped at wrap-up and the console's skip list is per-session, so the customer
 * got two calls a minute apart. These prove the claim closes that, that a claim
 * nobody released frees itself, and that a live console keeps the lead it is
 * working through a call longer than the 90-second expiry.
 */

/**
 * Two leads on one campaign, oldest-first by id, and the two agents who work them.
 *
 * @return array{0: Tenant, 1: User, 2: User, 3: int, 4: int, 5: int}
 */
function claimFixture(): array
{
    $tenant = Tenant::factory()->create();
    $first = clientUserWithRole($tenant, RoleName::Agent->value);
    $second = clientUserWithRole($tenant, RoleName::Agent->value);

    [$campaignId, $leadOneId, $leadTwoId] = TenantContext::run($tenant->id, function (): array {
        $campaign = Campaign::factory()->create(['is_active' => true]);

        $one = Lead::factory()->forCampaign($campaign)->status(LeadStatus::New)
            ->create(['phone' => '9991110001', 'attempts' => 0]);
        $two = Lead::factory()->forCampaign($campaign)->status(LeadStatus::New)
            ->create(['phone' => '9991110002', 'attempts' => 0]);

        return [$campaign->id, $one->id, $two->id];
    });

    return [$tenant, $first, $second, $campaignId, $leadOneId, $leadTwoId];
}

function consoleFor(int $campaignId): AgentConsole
{
    $page = new AgentConsole;
    $page->selectedCampaignId = $campaignId;

    return $page;
}

it('hands two agents dialing the same campaign two different leads', function () {
    [$tenant, $first, $second, $campaignId, $leadOneId, $leadTwoId] = claimFixture();

    // Both screens are showing the SAME lead — the preview does not claim, so this
    // is the exact moment the double-serve used to happen.
    $this->actingAs($first);
    $firstServed = TenantContext::run($tenant->id, fn (): ?array => consoleFor($campaignId)->servedLead());

    $this->actingAs($second);
    $secondServed = TenantContext::run($tenant->id, fn (): ?array => consoleFor($campaignId)->servedLead());

    expect($firstServed['id'])->toBe($leadOneId)
        ->and($secondServed['id'])->toBe($leadOneId);

    // Now both press Dial.
    $this->actingAs($first);
    $firstPage = TenantContext::run($tenant->id, function () use ($campaignId): AgentConsole {
        $page = consoleFor($campaignId);
        $page->dial();

        return $page;
    });

    $this->actingAs($second);
    $secondPage = TenantContext::run($tenant->id, function () use ($campaignId): AgentConsole {
        $page = consoleFor($campaignId);
        $page->dial();

        return $page;
    });

    expect($firstPage->matchedLeadId)->toBe($leadOneId)
        ->and($secondPage->matchedLeadId)->toBe($leadTwoId);

    // Both leads are held, so a third agent would be told there is nothing to dial.
    $this->actingAs($first);
    $third = TenantContext::run($tenant->id, fn (): ?array => consoleFor($campaignId)->servedLead());

    expect($third)->toBeNull();
});

it('serves a lead again once its claim goes stale', function () {
    [$tenant, $first, $second, $campaignId, $leadOneId] = claimFixture();

    $this->actingAs($first);
    TenantContext::run($tenant->id, fn () => consoleFor($campaignId)->dial());

    // A dialer that crashed, or a laptop that was closed: nobody released the claim.
    $this->travel(Lead::CLAIM_TTL_SECONDS + 1)->seconds();

    $this->actingAs($second);
    $served = TenantContext::run($tenant->id, fn (): ?array => consoleFor($campaignId)->servedLead());

    expect($served['id'])->toBe($leadOneId);
});

it('keeps the lead held through a call longer than the claim expiry', function () {
    [$tenant, $first, $second, $campaignId, $leadOneId, $leadTwoId] = claimFixture();

    $this->actingAs($first);
    $page = TenantContext::run($tenant->id, function () use ($campaignId): AgentConsole {
        $page = consoleFor($campaignId);
        $page->dial();

        return $page;
    });

    // A real conversation outruns the 90-second expiry. The screen's own ~15s
    // "still here" tick is what keeps the lead held while the agent talks.
    $this->travel(60)->seconds();
    TenantContext::run($tenant->id, fn () => $page->heartbeat());
    $this->travel(60)->seconds();

    $this->actingAs($second);
    $served = TenantContext::run($tenant->id, fn (): ?array => consoleFor($campaignId)->servedLead());

    expect($served['id'])->toBe($leadTwoId); // the second agent gets the OTHER lead
});

it('puts the lead back in the pool at wrap-up', function () {
    [$tenant, $first, $second, $campaignId, $leadOneId] = claimFixture();

    $dispositionId = TenantContext::run($tenant->id, fn (): int => Disposition::factory()
        ->create(['is_contact' => true, 'label' => 'No answer'])->id);

    $this->actingAs($first);
    TenantContext::run($tenant->id, function () use ($campaignId, $dispositionId): void {
        $page = consoleFor($campaignId);
        $page->dial();
        $page->saveWrapUp($dispositionId);
    });

    $lead = TenantContext::run($tenant->id, fn (): ?Lead => Lead::find($leadOneId));

    // Released, not left to expire: a re-dial is immediate rather than 90s away.
    expect($lead->claimed_at)->toBeNull()
        ->and($lead->attempts)->toBe(1);

    // It is now the SECOND lead in line (one attempt against the other's zero), which
    // is the serving rule doing its job, not the claim.
    $this->actingAs($second);
    $served = TenantContext::run($tenant->id, fn (): ?array => consoleFor($campaignId)->servedLead());

    expect($served['id'])->not->toBe($leadOneId);
});
