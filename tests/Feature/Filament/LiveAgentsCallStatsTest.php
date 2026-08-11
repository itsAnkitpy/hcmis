<?php

use App\Enums\RoleName;
use App\Filament\Pages\LiveAgents;
use App\Models\Tenant;
use App\Telephony\LiveCallCounts;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/**
 * Call Stats CS-5/CS-7 — the three live call numbers as the Live Agents board shows
 * them: how many calls are connected, how many are ringing, how many callers are
 * holding.
 *
 * The board never counts anything. The always-on listener leaves the numbers in the
 * cache and this screen picks them up, so these tests write the notes directly (through
 * the same small class both programs use) and prove what the screen does with them.
 *
 * Nothing here needs a phone line, and nothing here needs the listener running.
 */
uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::resetWebRequest();
    TenantContext::forget();
});

it('shows a team leader their own client\'s numbers and never another client\'s', function () {
    $ours = Tenant::factory()->create();
    $theirs = Tenant::factory()->create();
    $leader = clientUserWithRole($ours, RoleName::TeamLeader->value);

    (new LiveCallCounts)->publish([
        $ours->id => ['active' => 2, 'ringing' => 1, 'waiting' => 3],
        $theirs->id => ['active' => 9, 'ringing' => 9, 'waiting' => 9],
    ]);

    $this->actingAs($leader->fresh());
    TenantContext::applyWebRequest($ours->id, crossTenant: false);

    expect((new LiveAgents)->callStats())->toBe(['active' => 2, 'ringing' => 1, 'waiting' => 3]);
});

it('adds every client together for our own global staff', function () {
    $one = Tenant::factory()->create();
    $two = Tenant::factory()->create();
    Tenant::factory()->create();   // a third client with no calls at all — contributes zeros

    (new LiveCallCounts)->publish([
        $one->id => ['active' => 2, 'ringing' => 1, 'waiting' => 3],
        $two->id => ['active' => 1, 'ringing' => 0, 'waiting' => 4],
    ]);

    $this->actingAs(reportsHcUser(RoleName::OpsManager->value)->fresh());
    TenantContext::applyWebRequest(null, crossTenant: true);

    expect((new LiveAgents)->callStats())->toBe(['active' => 3, 'ringing' => 1, 'waiting' => 7]);
});

it('shows zeros on a calm floor, because the phone service is still reporting', function () {
    $tenant = Tenant::factory()->create();
    $leader = clientUserWithRole($tenant, RoleName::TeamLeader->value);

    (new LiveCallCounts)->publish([]);   // the listener is running; nothing is happening

    $this->actingAs($leader->fresh());
    TenantContext::applyWebRequest($tenant->id, crossTenant: false);

    expect((new LiveAgents)->callStats())->toBe(['active' => 0, 'ringing' => 0, 'waiting' => 0]);

    Livewire::test(LiveAgents::class)
        ->assertSee('Calls right now')
        ->assertDontSee('phone service not reporting');
});

it('says the phone service is not reporting rather than drawing three zeros', function () {
    $tenant = Tenant::factory()->create();
    $leader = clientUserWithRole($tenant, RoleName::TeamLeader->value);

    // Nothing published: the listener is not running, or died and its notes expired.
    $this->actingAs($leader->fresh());
    TenantContext::applyWebRequest($tenant->id, crossTenant: false);

    expect((new LiveAgents)->callStats())->toBeNull();

    Livewire::test(LiveAgents::class)->assertSee('phone service not reporting');
});
