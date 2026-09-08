<?php

use App\Enums\LeadStatus;
use App\Enums\RoleName;
use App\Filament\Pages\AgentConsole;
use App\Models\Call;
use App\Models\Callback;
use App\Models\Campaign;
use App\Models\Disposition;
use App\Models\Lead;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => fakeAgentDirectory());   // SEC-1 slice 4: the reserved agent has a phone

afterEach(function () {
    TenantContext::forget();
});

/**
 * CP-5 (N1b) — the agent's note about the call, written onto the `calls` row.
 *
 * The contract worth pinning: BOTH ways a call closes carry the note, because both
 * write a row through the one writer (recordCall) — a matched wrap-up and the
 * no-match/ad-hoc Done. The bug this guards is the one N1a already taught: wiring a
 * new field into the path the ticket names and leaving the sibling path blank.
 */
function seedNotesCampaign(Tenant $tenant): array
{
    return TenantContext::run($tenant->id, function (): array {
        $campaign = Campaign::factory()->create(['is_active' => true]);
        $disposition = Disposition::factory()->forCampaign($campaign)->create([
            'code' => 'INTERESTED',
            'label' => 'Interested',
            'is_contact' => true,
        ]);
        Lead::factory()->forCampaign($campaign)->status(LeadStatus::New)->create([
            'phone' => '9991234567',
            'attempts' => 0,
        ]);

        return [$campaign->id, $disposition->id];
    });
}

function fakeDial(): void
{
    config()->set('telephony.agent.endpoint', 'PJSIP/1003');
    config()->set('telephony.outbound.caller_id', '18001234567');
    Http::fake(['*' => Http::response(['id' => 'agent-leg'])]);
}

it('writes the note onto the call row of a matched wrap-up', function () {
    fakeDial();

    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    [$campaignId, $dispositionId] = seedNotesCampaign($tenant);

    $this->actingAs($agent);

    TenantContext::run($tenant->id, function () use ($campaignId, $dispositionId): void {
        $page = new AgentConsole;
        $page->selectedCampaignId = $campaignId;
        $page->dial();
        $page->saveWrapUp($dispositionId, callNotes: 'Wants the quote emailed by Friday.');
    });

    $call = TenantContext::run($tenant->id, fn (): ?Call => Call::query()->latest('id')->first());

    expect($call->notes)->toBe('Wants the quote emailed by Friday.');
});

// The sibling path. An ad-hoc call to a stranger still writes a row (B3 D5), so it
// still has to carry the note — this is the half a fix aimed at wrap-up would miss.
it('writes the note onto the call row of a no-match Done', function () {
    fakeDial();

    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $this->actingAs($agent);
    TenantContext::applyWebRequest($tenant->id, crossTenant: false);

    Livewire::test(AgentConsole::class)
        ->call('dialAdhoc', '+919805907341')
        ->call('completeUnmatched', 'Rang the wrong department, redirected them.');

    $call = TenantContext::run($tenant->id, fn (): ?Call => Call::query()->latest('id')->first());

    expect($call->lead_id)->toBeNull()
        ->and($call->notes)->toBe('Rang the wrong department, redirected them.');

    TenantContext::resetWebRequest();
});

// A note is optional (A3, like every other field on this screen) and an empty box is
// an absence, not an empty string — a blank note must read as a dash, not as "".
it('stores nothing when the agent leaves the box empty', function () {
    fakeDial();

    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    [$campaignId, $dispositionId] = seedNotesCampaign($tenant);

    $this->actingAs($agent);

    TenantContext::run($tenant->id, function () use ($campaignId, $dispositionId): void {
        $page = new AgentConsole;
        $page->selectedCampaignId = $campaignId;
        $page->dial();
        $page->saveWrapUp($dispositionId, callNotes: '   ');
    });

    $call = TenantContext::run($tenant->id, fn (): ?Call => Call::query()->latest('id')->first());

    expect($call->notes)->toBeNull();
});

// The call note and the callback note are two different records of two different
// things: what was said, and why to ring back. Sending both must not smear one
// across the other.
it('keeps the call note apart from the callback note', function () {
    fakeDial();

    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    [$campaignId, $dispositionId] = TenantContext::run($tenant->id, function (): array {
        $campaign = Campaign::factory()->create(['is_active' => true]);
        $disposition = Disposition::factory()->forCampaign($campaign)->create([
            'code' => Disposition::CALLBACK_CODE,
            'label' => 'Callback',
            'is_contact' => true,
        ]);
        Lead::factory()->forCampaign($campaign)->status(LeadStatus::New)->create([
            'phone' => '9991234567',
            'attempts' => 0,
        ]);

        return [$campaign->id, $disposition->id];
    });

    $this->actingAs($agent);

    TenantContext::run($tenant->id, function () use ($campaignId, $dispositionId): void {
        $page = new AgentConsole;
        $page->selectedCampaignId = $campaignId;
        $page->dial();
        $page->saveWrapUp(
            $dispositionId,
            now()->addDay()->toIso8601String(),
            'Prefers evenings.',
            false,
            'Asked about the annual plan.',
        );
    });

    [$call, $callback] = TenantContext::run($tenant->id, fn (): array => [
        Call::query()->latest('id')->first(),
        Callback::query()->latest('id')->first(),
    ]);

    expect($call->notes)->toBe('Asked about the annual plan.')
        ->and($callback->notes)->toBe('Prefers evenings.');
});

// The server is the wall: a note longer than the column is meant to hold is refused,
// not silently truncated into a half-sentence the client later reads as the record.
it('refuses a note longer than the console allows', function () {
    fakeDial();

    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    [$campaignId, $dispositionId] = seedNotesCampaign($tenant);

    $this->actingAs($agent);
    TenantContext::applyWebRequest($tenant->id, crossTenant: false);

    Livewire::test(AgentConsole::class)
        ->set('selectedCampaignId', $campaignId)
        ->call('dial')
        ->call('saveWrapUp', $dispositionId, null, null, false, str_repeat('a', 2001))
        ->assertHasErrors('notes');

    expect(TenantContext::run($tenant->id, fn (): int => Call::count()))->toBe(0);

    TenantContext::resetWebRequest();
});

// The point of writing one down: the next agent to speak to this number sees it while
// the phone is still in their hand. Pinned in BOTH halves — the payload carries it and
// the panel binds it — because either one alone shows the agent nothing.
it('hands the note to the next agent through the caller history', function () {
    fakeDial();

    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    [$campaignId, $dispositionId] = seedNotesCampaign($tenant);

    $this->actingAs($agent);

    TenantContext::run($tenant->id, function () use ($campaignId, $dispositionId): void {
        $page = new AgentConsole;
        $page->selectedCampaignId = $campaignId;
        $page->dial();
        $page->saveWrapUp($dispositionId, callNotes: 'Angry about the third bill. Handle gently.');
    });

    TenantContext::applyWebRequest($tenant->id, crossTenant: false);

    Livewire::test(AgentConsole::class)
        ->call('dialAdhoc', '9991234567')
        ->call('callHistory')
        ->assertReturned(fn (array $history): bool => $history['calls'][0]['notes'] === 'Angry about the third bill. Handle gently.');

    expect(Livewire::test(AgentConsole::class)->html())->toContain('call.notes');

    TenantContext::resetWebRequest();
});

// The markup half — the same lesson CH-2 and CP-3 earned. The note is worthless if
// the box bound to it is gone, and it must be reachable on the live call AND at
// wrap-up, since either moment can be the one the agent types in.
it('carries the notes box bound to the call state, on the call and at wrap-up', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $this->actingAs($agent);
    TenantContext::applyWebRequest($tenant->id, crossTenant: false);

    $html = Livewire::test(AgentConsole::class)->html();

    expect(substr_count($html, 'x-model="callNotes"'))->toBe(3)
        ->and($html)->toContain('id="liveCallNotes"')
        ->and($html)->toContain('id="wrapUpCallNotes"')
        ->and($html)->toContain('id="unmatchedCallNotes"');

    TenantContext::resetWebRequest();
});

// O3 layout: the live-call panels sit in a two-column grid so they stop stacking as
// more are added. Pinned because the next panel (call scripts) has to land in it.
//
// A CONTAINER query, not a screen one: the console is a fixed-width column in the
// middle of the page, so a screen-width breakpoint splits it while the card itself is
// still too narrow to type in. The card measures itself.
it('lays the live-call panels out in two columns', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $this->actingAs($agent);
    TenantContext::applyWebRequest($tenant->id, crossTenant: false);

    $html = Livewire::test(AgentConsole::class)->html();

    expect($html)->toContain('@3xl:grid-cols-2')
        // Each COLUMN is a container in its own right, and this is the assertion that
        // stops the trap from biting a third time. A container query resolves against
        // the nearest ANCESTOR container, so without these two marks every breakpoint
        // written inside a column would measure the whole card and lay fields out two
        // across in a half-width column. Silent, and only visible on a wide window.
        ->toContain('@container space-y-5')
        ->toContain('@container border-t border-gray-100 pt-5 @3xl:border-l');

    TenantContext::resetWebRequest();
});
