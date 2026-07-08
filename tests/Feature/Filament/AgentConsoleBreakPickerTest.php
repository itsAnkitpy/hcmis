<?php

use App\Enums\PresenceStatus;
use App\Enums\RoleName;
use App\Filament\Pages\AgentConsole;
use App\Models\AgentPresence;
use App\Models\AgentStatusHistory;
use App\Models\BreakCategory;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::forget();
});

/**
 * BK-3 — the break picker's feed: AgentConsole::breakCategoryOptions() serves
 * the client's ACTIVE break types in display order, each with the limit the
 * browser counts down from. An empty list is the signal to skip the picker and
 * take a plain untyped break (the fallback). Driven directly in tenant context,
 * the AgentStatusHistoryTest pattern.
 */

/** The picker feed as the browser receives it, read inside the client. */
function breakPickerOptions(Tenant $tenant): array
{
    return TenantContext::run($tenant->id, fn () => (new AgentConsole)->breakCategoryOptions());
}

/** Retire the six seeded defaults so a test controls exactly what's active. */
function deactivateSeededBreakCategories(Tenant $tenant): void
{
    TenantContext::run($tenant->id, fn () => BreakCategory::query()->update(['is_active' => false]));
}

it('lists only active break types, in display order, with their limits', function () {
    $tenant = Tenant::factory()->create();
    deactivateSeededBreakCategories($tenant);

    [$tea, $lunch] = TenantContext::run($tenant->id, fn (): array => [
        BreakCategory::factory()->withLimit(15)->create(['code' => 'TEA_TEST', 'label' => 'Tea', 'sort_order' => 20]),
        BreakCategory::factory()->create(['code' => 'LUNCH_TEST', 'label' => 'Lunch', 'sort_order' => 10]),
        BreakCategory::factory()->inactive()->withLimit(5)->create(['code' => 'RETIRED_TEST', 'sort_order' => 1]),
    ]);

    expect(breakPickerOptions($tenant))->toBe([
        // Lunch first (sort_order 10 before 20); the deactivated type never shows.
        ['id' => $lunch->id, 'label' => 'Lunch', 'limitMinutes' => null],
        ['id' => $tea->id, 'label' => 'Tea', 'limitMinutes' => 15],
    ]);
});

it('serves an empty list when every type is deactivated — the untyped-break fallback signal', function () {
    $tenant = Tenant::factory()->create();
    deactivateSeededBreakCategories($tenant);

    expect(breakPickerOptions($tenant))->toBe([]);
});

it('never lists another client\'s break types (the tenant wall)', function () {
    $clientA = Tenant::factory()->create();
    $clientB = Tenant::factory()->create();
    deactivateSeededBreakCategories($clientA);

    // Client B holds an active, limited type; client A's picker must stay empty.
    TenantContext::run(
        $clientB->id,
        fn () => BreakCategory::factory()->withLimit(30)->create(['code' => 'FOREIGN_TEST']),
    );

    expect(breakPickerOptions($clientA))->toBe([]);
});

// --- BK-7: resume-on-return — the loading console asks the server before assuming Ready ---

it('offers a still-fresh break back to a reloading console, anchored on the original start', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    [$stint, $lunch] = TenantContext::run($tenant->id, function () use ($agent): array {
        $lunch = BreakCategory::factory()->withLimit(30)->create(['code' => 'LUNCH_TEST', 'label' => 'Lunch']);
        AgentPresence::factory()->forUser($agent)->status(PresenceStatus::OnBreak)->create();

        // ->fresh(): compare against the STORED start (seconds precision), the
        // same value resumableBreak() reads back.
        $stint = AgentStatusHistory::factory()->forUser($agent)->onBreak($lunch)
            ->create(['started_at' => now()->subMinutes(10)])->fresh();

        return [$stint, $lunch];
    });

    $this->actingAs($agent);

    $resume = TenantContext::run($tenant->id, fn (): ?array => (new AgentConsole)->resumableBreak());

    expect($resume)->toBe([
        'startedAtMs' => $stint->started_at->getTimestampMs(),
        'category' => ['id' => $lunch->id, 'label' => 'Lunch', 'limitMinutes' => 30],
    ]);
});

it('resumes an untyped break with no category attached', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    TenantContext::run($tenant->id, function () use ($agent): void {
        AgentPresence::factory()->forUser($agent)->status(PresenceStatus::OnBreak)->create();
        AgentStatusHistory::factory()->forUser($agent)->status(PresenceStatus::OnBreak)
            ->create(['started_at' => now()->subMinutes(5)]);
    });

    $this->actingAs($agent);

    $resume = TenantContext::run($tenant->id, fn (): ?array => (new AgentConsole)->resumableBreak());

    expect($resume)->not->toBeNull()
        ->and($resume['category'])->toBeNull();
});

it('offers nothing when the session went stale — a dead break stays dead (BK-6)', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    TenantContext::run($tenant->id, function () use ($agent): void {
        AgentPresence::factory()->forUser($agent)->status(PresenceStatus::OnBreak)->stale()->create();
        AgentStatusHistory::factory()->forUser($agent)->status(PresenceStatus::OnBreak)
            ->create(['started_at' => now()->subMinutes(20)]);
    });

    $this->actingAs($agent);

    expect(TenantContext::run($tenant->id, fn (): ?array => (new AgentConsole)->resumableBreak()))->toBeNull();
});

it('offers nothing when the agent is not on break', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    TenantContext::run($tenant->id, function () use ($agent): void {
        AgentPresence::factory()->forUser($agent)->status(PresenceStatus::Ready)->create();
    });

    $this->actingAs($agent);

    expect(TenantContext::run($tenant->id, fn (): ?array => (new AgentConsole)->resumableBreak()))->toBeNull();
});

it('offers nothing when the board says on break but no open stint anchors it', function () {
    // Both halves must agree (the slice-4 coherence rule): a fresh on-break row
    // with no open stint has no clock to resume — fall through to the normal boot.
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    TenantContext::run($tenant->id, function () use ($agent): void {
        AgentPresence::factory()->forUser($agent)->status(PresenceStatus::OnBreak)->create();
    });

    $this->actingAs($agent);

    expect(TenantContext::run($tenant->id, fn (): ?array => (new AgentConsole)->resumableBreak()))->toBeNull();
});
