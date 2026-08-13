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

    $this->freezeTime();
    $ourOldest = now()->subMinutes(2)->getTimestamp();

    (new LiveCallCounts)->publish([
        $ours->id => ['active' => 2, 'ringing' => 1, 'waiting' => 3, 'oldestWaitingAt' => $ourOldest],
        $theirs->id => ['active' => 9, 'ringing' => 9, 'waiting' => 9, 'oldestWaitingAt' => now()->subHour()->getTimestamp()],
    ]);

    $this->actingAs($leader->fresh());
    TenantContext::applyWebRequest($ours->id, crossTenant: false);

    // The other client's much older caller must not leak in — it would be the bigger
    // number, so a wrong answer here shows up as an alarming one.
    expect((new LiveAgents)->callStats())->toBe(['active' => 2, 'ringing' => 1, 'waiting' => 3, 'oldestWaitingAt' => $ourOldest]);

    Livewire::test(LiveAgents::class)
        ->assertSee('Longest wait')
        // LW-7: the colour turns amber past 3 minutes and red past 5, off the same
        // ticking number the text reads, so it changes on the wall screen between page
        // refreshes. Only the thresholds can be checked from here — the switch itself
        // happens in the browser.
        ->assertSee('held >= 300', escape: false)
        ->assertSee('held >= 180', escape: false);
});

it('adds every client together for our own global staff', function () {
    $one = Tenant::factory()->create();
    $two = Tenant::factory()->create();
    Tenant::factory()->create();   // a third client with no calls at all — contributes zeros

    $this->freezeTime();
    $longest = now()->subMinutes(6)->getTimestamp();

    (new LiveCallCounts)->publish([
        $one->id => ['active' => 2, 'ringing' => 1, 'waiting' => 3, 'oldestWaitingAt' => now()->subMinutes(2)->getTimestamp()],
        $two->id => ['active' => 1, 'ringing' => 0, 'waiting' => 4, 'oldestWaitingAt' => $longest],
    ]);

    $this->actingAs(reportsHcUser(RoleName::OpsManager->value)->fresh());
    TenantContext::applyWebRequest(null, crossTenant: true);

    // Counts add across clients; the wait does not. Six minutes and two minutes is a
    // longest wait of six (LW-3).
    expect((new LiveAgents)->callStats())->toBe(['active' => 3, 'ringing' => 1, 'waiting' => 7, 'oldestWaitingAt' => $longest]);
});

it('shows zeros on a calm floor, because the phone service is still reporting', function () {
    $tenant = Tenant::factory()->create();
    $leader = clientUserWithRole($tenant, RoleName::TeamLeader->value);

    (new LiveCallCounts)->publish([]);   // the listener is running; nothing is happening

    $this->actingAs($leader->fresh());
    TenantContext::applyWebRequest($tenant->id, crossTenant: false);

    expect((new LiveAgents)->callStats())->toBe(['active' => 0, 'ringing' => 0, 'waiting' => 0, 'oldestWaitingAt' => null]);

    Livewire::test(LiveAgents::class)
        ->assertSee('Calls right now')
        ->assertDontSee('phone service not reporting')
        // LW-4: nobody is holding, so there is no clock at all — not a clock reading zero.
        ->assertDontSee('Longest wait');
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
