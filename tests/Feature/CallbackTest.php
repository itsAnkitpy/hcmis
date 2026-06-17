<?php

use App\Enums\CallbackStatus;
use App\Models\Callback;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::forget();
});

/**
 * M4 (PR2) Phase 1 — the callbacks table + model. The three-layer tenant wall
 * is proven by the shared `tenantModels` dataset in OperationalModelIsolationTest;
 * this file covers the callback-specific shape: casts, relations, defaults.
 */
it('casts status to the enum and scheduled_at to a Carbon instance', function () {
    $tenant = Tenant::factory()->create();

    $callback = TenantContext::run($tenant->id, fn (): Callback => Callback::factory()->create([
        'scheduled_at' => '2026-07-01 17:30:00',
    ]));

    expect($callback->status)->toBe(CallbackStatus::Pending)        // default
        ->and($callback->scheduled_at)->toBeInstanceOf(Carbon::class)
        ->and($callback->scheduled_at->format('Y-m-d H:i'))->toBe('2026-07-01 17:30');
});

it('defaults to pending and unowned (pooled-shaped) until made sticky', function () {
    $tenant = Tenant::factory()->create();

    $callback = TenantContext::run($tenant->id, fn (): Callback => Callback::factory()->create());

    expect($callback->status)->toBe(CallbackStatus::Pending)
        ->and($callback->owner_agent_id)->toBeNull();
});

it('keeps lead_id and campaign_id consistent and wires the relations', function () {
    $tenant = Tenant::factory()->create();

    // Relations are tenant-owned, so resolve + assert them inside the context
    // (a read outside any tenant context is default-denied — the M4.B wall).
    TenantContext::run($tenant->id, function (): void {
        $lead = Lead::factory()->create();
        $callback = Callback::factory()->forLead($lead)->create();

        expect($callback->lead_id)->toBe($lead->id)
            ->and($callback->campaign_id)->toBe($lead->campaign_id)
            ->and($callback->lead)->toBeInstanceOf(Lead::class)
            ->and($callback->campaign->id)->toBe($lead->campaign_id);
    });
});

it('binds a sticky callback to its owning agent', function () {
    $tenant = Tenant::factory()->create();
    $agent = User::factory()->create();

    $callback = TenantContext::run($tenant->id, fn (): Callback => Callback::factory()->forAgent($agent)->create());

    expect($callback->owner_agent_id)->toBe($agent->id)
        ->and($callback->ownerAgent->is($agent))->toBeTrue();
});

it('exposes due and not-yet-due factory states', function () {
    $tenant = Tenant::factory()->create();

    [$due, $later] = TenantContext::run($tenant->id, fn (): array => [
        Callback::factory()->due()->create(),
        Callback::factory()->notYetDue()->create(),
    ]);

    expect($due->scheduled_at->isPast())->toBeTrue()
        ->and($later->scheduled_at->isFuture())->toBeTrue();
});
