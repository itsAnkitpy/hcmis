<?php

use App\Enums\RoleName;
use App\Filament\Pages\AgentConsole;
use App\Models\CallHandoff;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::forget();
});

/**
 * B2.4b TH-2/TH-3 — the screen's ring-time claim. claimHandoffTicket() reads the
 * most-recent handoff note the listener left for the logged-in agent and holds its
 * ticket as callCorrelationId, so the wrap-up stamps it on the calls row. Driven
 * directly (the path the browser runs over $wire), in the agent's tenant context.
 * Option A: the claim owns callCorrelationId, so the lead lookup can never clobber it
 * and a miss degrades to null gracefully.
 */
it('claims the agent\'s most-recent handoff ticket into callCorrelationId — no lead lookup needed (anonymous-safe)', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    TenantContext::run($tenant->id, function () use ($agent): void {
        CallHandoff::factory()->forAgent($agent)->ticket('older')->create();
        CallHandoff::factory()->forAgent($agent)->ticket('newest')->create();   // higher id = most recent
    });

    $this->actingAs($agent);

    $page = new AgentConsole;
    TenantContext::run($tenant->id, fn () => $page->claimHandoffTicket());

    // Claimed without ever calling lookupLead — so an anonymous caller (who skips the
    // lead lookup) still attaches (Finding A); most-recent by id (TH-2).
    expect($page->callCorrelationId)->toBe('newest');
});

it('leaves callCorrelationId null when there is no handoff note — graceful miss, no error (TH-2)', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $this->actingAs($agent);

    $page = new AgentConsole;
    TenantContext::run($tenant->id, fn () => $page->claimHandoffTicket());

    expect($page->callCorrelationId)->toBeNull();   // null, never a crash, never the wrong ticket
});

it('the lead lookup never clobbers a just-claimed ticket (Option A — callCorrelationId out of resetMatch)', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    TenantContext::run($tenant->id, fn () => CallHandoff::factory()->forAgent($agent)->ticket('the-ticket')->create());

    $this->actingAs($agent);

    $page = new AgentConsole;
    TenantContext::run($tenant->id, function () use ($page): void {
        $page->claimHandoffTicket();        // claim first (sets callCorrelationId)
        $page->lookupLead('9995551111');    // then the lead lookup, which calls resetMatch()
    });

    // resetMatch() no longer touches callCorrelationId, so the just-claimed ticket survives
    // the lead lookup that runs on the same ring (the hazard Option A closes).
    expect($page->callCorrelationId)->toBe('the-ticket');
});

it('reads only its own client\'s notes — another client\'s note is invisible across the wall (TH-5)', function () {
    $clientA = Tenant::factory()->create();
    $clientB = Tenant::factory()->create();
    $agentA = clientUserWithRole($clientA, RoleName::Agent->value);

    // Client B has a note for an agent that happens to share agentA's id space? No — keep it
    // honest: B's note is for B's own agent. A's claim must see nothing of B's.
    $agentB = clientUserWithRole($clientB, RoleName::Agent->value);
    TenantContext::run($clientB->id, fn () => CallHandoff::factory()->forAgent($agentB)->ticket('b-ticket')->create());

    $this->actingAs($agentA);

    $page = new AgentConsole;
    TenantContext::run($clientA->id, fn () => $page->claimHandoffTicket());

    expect($page->callCorrelationId)->toBeNull();   // A's drawer is empty; B's note is walled off
});
