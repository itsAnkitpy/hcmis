<?php

use App\Models\CallHandoff;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantContextMissingException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::forget();
});

/**
 * B2.4b TH-1/TH-5 — the listener->browser ticket-handoff drop-off table. Tenant-owned
 * and RLS-walled like agent_presence: a note is found by agent identity within a client,
 * a write needs tenant context, and one client never sees another's notes. The per-call
 * shape (>1 note per agent allowed, no unique on agent_user_id) is the WD-seam
 * forward-compat that sent TH-1 to a dedicated table.
 */
it('stamps tenant_id from context and reads the note back by agent', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, 'agent');

    TenantContext::run($tenant->id, function () use ($agent): void {
        CallHandoff::factory()->forAgent($agent)->ticket('the-ticket')->create();
    });

    $note = TenantContext::run(
        $tenant->id,
        fn () => CallHandoff::query()->where('agent_user_id', $agent->id)->first(),
    );

    expect($note)->not->toBeNull()
        ->and($note->tenant_id)->toBe($tenant->id)   // auto-stamped (BelongsToTenant)
        ->and($note->ticket)->toBe('the-ticket');
});

it('holds MORE THAN ONE note per agent — no unique on agent_user_id (the WD-seam shape, TH-1)', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, 'agent');

    TenantContext::run($tenant->id, function () use ($agent): void {
        CallHandoff::factory()->forAgent($agent)->ticket('first')->create();
        CallHandoff::factory()->forAgent($agent)->ticket('second')->create();
    });

    $tickets = TenantContext::run(
        $tenant->id,
        fn () => CallHandoff::query()->where('agent_user_id', $agent->id)->orderBy('id')->pluck('ticket')->all(),
    );

    // The table accepts two live notes for one agent (prune-on-write caps it to one in
    // practice, TH-6 — but the SHAPE must allow more for warm transfer later).
    expect($tickets)->toBe(['first', 'second']);
});

it('walls the drawer per client — one tenant never sees another\'s note', function () {
    $clientA = Tenant::factory()->create();
    $clientB = Tenant::factory()->create();
    $agentB = clientUserWithRole($clientB, 'agent');

    // Client B drops a note for its own agent.
    TenantContext::run($clientB->id, fn () => CallHandoff::factory()->forAgent($agentB)->create());

    // Client A, in its own context, sees an empty drawer — B's note is across the wall.
    $aSees = TenantContext::run($clientA->id, fn () => CallHandoff::query()->count());
    $bSees = TenantContext::run($clientB->id, fn () => CallHandoff::query()->count());

    expect($aSees)->toBe(0)   // RLS default-deny across the wall
        ->and($bSees)->toBe(1);
});

it('refuses a context-less write — a note can never be written without a client (TH-5)', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, 'agent');

    // No TenantContext::run wrapper — BelongsToTenant has no tenant to stamp.
    CallHandoff::factory()->forAgent($agent)->create();
})->throws(TenantContextMissingException::class);
