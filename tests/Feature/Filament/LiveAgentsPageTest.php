<?php

use App\Enums\PresenceStatus;
use App\Enums\RoleName;
use App\Enums\StintEndedVia;
use App\Filament\Pages\LiveAgents;
use App\Models\ActivityLog;
use App\Models\AgentPresence;
use App\Models\AgentStatusHistory;
use App\Models\BreakCategory;
use App\Models\Call;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::resetWebRequest();
    TenantContext::forget();
});

/**
 * A working agent: a board row + a matching open stint, so the roster reads a real
 * time-in-status and (for a break) the category detail. Call inside a tenant context.
 */
function floorAgent(string $name, PresenceStatus $status, int $sinceMinutes = 5, ?BreakCategory $break = null, bool $stale = false): User
{
    $user = User::factory()->create(['name' => $name]);

    $presence = AgentPresence::factory()->forUser($user)->status($status);
    $presence = $stale ? $presence->stale() : $presence;
    $presence->create();

    $stint = AgentStatusHistory::factory()->forUser($user);
    $stint = $break !== null ? $stint->onBreak($break) : $stint->status($status);
    $stint->create(['started_at' => now()->subMinutes($sinceMinutes)]);

    return $user;
}

// --- LB gate: same audience as the dashboard/reports ---

it('lets a reporting role onto the board and keeps agents out', function () {
    $tenant = Tenant::factory()->create();
    $leader = clientUserWithRole($tenant, RoleName::TeamLeader->value);
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    TenantContext::run($tenant->id, function () use ($leader, $agent) {
        $this->actingAs($leader->fresh());
        expect(LiveAgents::canAccess())->toBeTrue();

        $this->actingAs($agent->fresh());
        expect(LiveAgents::canAccess())->toBeFalse();
    });
});

// --- LB-1: only currently-active agents; offline + stale drop off ---

it('lists only currently-active agents, hiding offline and stale ones', function () {
    $tenant = Tenant::factory()->create();

    $rows = TenantContext::run($tenant->id, function (): array {
        floorAgent('Ready Rita', PresenceStatus::Ready);
        floorAgent('Calling Carl', PresenceStatus::OnCall);
        floorAgent('Offline Omar', PresenceStatus::Offline);          // logged out
        floorAgent('Stale Sam', PresenceStatus::Ready, stale: true);  // heartbeat gone quiet → Offline

        return (new LiveAgents)->roster();
    });

    $names = array_column($rows, 'name');

    expect($rows)->toHaveCount(2)
        ->and($names)->toContain('Ready Rita', 'Calling Carl')
        ->and($names)->not->toContain('Offline Omar', 'Stale Sam');
});

// --- LB-3: break detail + the red overstay flag (computed, never stored — BK-4) ---

it('shows break detail and flags only the break that is over its limit', function () {
    $tenant = Tenant::factory()->create();

    $rows = TenantContext::run($tenant->id, function (): array {
        $lunch = BreakCategory::factory()->withLimit(30)->create(['code' => 'LUNCH', 'label' => 'Lunch']);
        floorAgent('Over Olga', PresenceStatus::OnBreak, sinceMinutes: 45, break: $lunch);  // 45 > 30 → over
        floorAgent('Fresh Fred', PresenceStatus::OnBreak, sinceMinutes: 10, break: $lunch); // 10 < 30 → fine

        return (new LiveAgents)->roster();
    });

    $byName = collect($rows)->keyBy('name');

    expect($byName['Over Olga']['breakCategory'])->toBe('Lunch')
        ->and($byName['Over Olga']['limitMinutes'])->toBe(30)
        ->and($byName['Over Olga']['overstayed'])->toBeTrue()
        ->and($byName['Fresh Fred']['overstayed'])->toBeFalse();
});

// BK-3 fallback, inherited from the dashboard's break-detail widget when that was
// removed as a duplicate of this board (S91): a client with no active break categories
// gets untyped stints, which must read as a plain "Break" with nothing to overstay.

it('shows an untyped break as a plain "Break" with no limit and no overstay', function () {
    $tenant = Tenant::factory()->create();

    $rows = TenantContext::run($tenant->id, function (): array {
        floorAgent('Untyped Uma', PresenceStatus::OnBreak, sinceMinutes: 90); // no category

        return (new LiveAgents)->roster();
    });

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['breakCategory'])->toBe('Break')
        ->and($rows[0]['limitMinutes'])->toBeNull()
        ->and($rows[0]['overstayed'])->toBeFalse();
});

// --- LB-3: calls today from the shared counting layer (never disagrees with reports) ---

it("counts each agent's calls handled today, today only", function () {
    $tenant = Tenant::factory()->create();

    $rows = TenantContext::run($tenant->id, function (): array {
        $rita = floorAgent('Ready Rita', PresenceStatus::Ready);
        Call::factory()->count(3)->forAgent($rita)->create(['created_at' => now()]);
        Call::factory()->forAgent($rita)->create(['created_at' => now()->subDays(2)]); // not today

        return (new LiveAgents)->roster();
    });

    expect($rows[0]['callsToday'])->toBe(3);
});

// --- LB-1 ordering: most-actionable first ---

it('orders on-a-call first and on-break last', function () {
    $tenant = Tenant::factory()->create();

    $rows = TenantContext::run($tenant->id, function (): array {
        $tea = BreakCategory::factory()->withLimit(15)->create(['code' => 'TEA', 'label' => 'Tea']);
        floorAgent('Breaker', PresenceStatus::OnBreak, break: $tea);
        floorAgent('Reader', PresenceStatus::Ready);
        floorAgent('Caller', PresenceStatus::OnCall);

        return (new LiveAgents)->roster();
    });

    expect(array_column($rows, 'name'))->toBe(['Caller', 'Reader', 'Breaker']);
});

// --- The tenant wall holds ---

it("never shows another client's agents", function () {
    $clientA = Tenant::factory()->create();
    $clientB = Tenant::factory()->create();

    TenantContext::run($clientA->id, fn () => floorAgent('A Agent', PresenceStatus::Ready));
    TenantContext::run($clientB->id, fn () => floorAgent('B Agent', PresenceStatus::Ready));

    $rows = TenantContext::run($clientA->id, fn (): array => (new LiveAgents)->roster());

    expect(array_column($rows, 'name'))->toBe(['A Agent']);
});

// --- LB-9: the tabs narrow the list; the counts match the rows ---

it('counts every tab off the roster, including the empty ones', function () {
    $tenant = Tenant::factory()->create();

    $tabs = TenantContext::run($tenant->id, function (): array {
        floorAgent('Caller', PresenceStatus::OnCall);
        floorAgent('Reader One', PresenceStatus::Ready);
        floorAgent('Reader Two', PresenceStatus::Ready);

        $page = new LiveAgents;

        return $page->tabs($page->roster(), count($page->stuckRoster()));
    });

    expect(collect($tabs)->pluck('count', 'label')->all())->toBe([
        'On the floor' => 3,
        'On a call' => 1,
        'Wrapping up' => 0,   // kept at zero rather than disappearing
        'Ready' => 2,
        'On break' => 0,
        'Stuck' => 0,
    ]);
});

it('shows only the open tab\'s agents', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function (): void {
        floorAgent('Caller', PresenceStatus::OnCall);
        floorAgent('Reader', PresenceStatus::Ready);

        $page = new LiveAgents;
        $rows = $page->roster();

        expect(array_column($page->visibleRows($rows), 'name'))->toBe(['Caller', 'Reader']);

        $page->tab = PresenceStatus::Ready->value;

        expect(array_column($page->visibleRows($rows), 'name'))->toBe(['Reader']);
    });
});

it('keeps the chosen tab across the 15-second refresh', function () {
    $tenant = Tenant::factory()->create();
    $leader = clientUserWithRole($tenant, RoleName::TeamLeader->value);

    TenantContext::run($tenant->id, function () {
        floorAgent('Caller', PresenceStatus::OnCall);
        floorAgent('Reader', PresenceStatus::Ready);
    });

    $this->actingAs($leader);
    TenantContext::applyWebRequest($tenant->id, crossTenant: false);

    Livewire::test(LiveAgents::class)
        ->set('tab', PresenceStatus::OnCall->value)
        ->assertSee('Caller')
        ->assertDontSee('Reader')
        ->call('$refresh')
        ->assertSet('tab', PresenceStatus::OnCall->value)
        ->assertSee('Caller')
        ->assertDontSee('Reader');
});

// --- LB-11: the three clickable headings ---

it('reorders by each clickable heading and flips direction on a second click', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function (): void {
        $carl = floorAgent('Carl', PresenceStatus::OnCall, sinceMinutes: 30);
        floorAgent('alice', PresenceStatus::Ready, sinceMinutes: 5);
        $bela = floorAgent('Bela', PresenceStatus::OnBreak, sinceMinutes: 50);

        Call::factory()->count(2)->forAgent($carl)->create(['created_at' => now()]);
        Call::factory()->count(7)->forAgent($bela)->create(['created_at' => now()]);

        $page = new LiveAgents;
        $rows = $page->roster();

        // The board opens in urgency order — untouched by any of this (LB-11).
        expect(array_column($page->visibleRows($rows), 'name'))->toBe(['Carl', 'alice', 'Bela']);

        $page->sortBy('name');
        expect(array_column($page->visibleRows($rows), 'name'))->toBe(['alice', 'Bela', 'Carl']); // case-blind

        $page->sortBy('name'); // same heading again → the other way
        expect(array_column($page->visibleRows($rows), 'name'))->toBe(['Carl', 'Bela', 'alice']);

        $page->sortBy('inStatusMinutes');
        expect(array_column($page->visibleRows($rows), 'name'))->toBe(['alice', 'Carl', 'Bela']);

        $page->sortBy('callsToday');
        expect(array_column($page->visibleRows($rows), 'name'))->toBe(['alice', 'Carl', 'Bela']); // 0, 2, 7
    });
});

it('ignores a sort by a heading that is not clickable', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function (): void {
        floorAgent('Reader', PresenceStatus::Ready);
        floorAgent('Caller', PresenceStatus::OnCall);

        $page = new LiveAgents;
        $rows = $page->roster();

        $page->sortBy('status');           // not on the list
        $page->sort = 'breakCategory';     // nor is a hand-set one from the browser

        expect(array_column($page->visibleRows($rows), 'name'))->toBe(['Caller', 'Reader']);
    });
});

it('keeps the chosen sort across the 15-second refresh', function () {
    $tenant = Tenant::factory()->create();
    $leader = clientUserWithRole($tenant, RoleName::TeamLeader->value);

    TenantContext::run($tenant->id, function () {
        floorAgent('Caller', PresenceStatus::OnCall);
        floorAgent('Reader', PresenceStatus::Ready);
    });

    $this->actingAs($leader);
    TenantContext::applyWebRequest($tenant->id, crossTenant: false);

    Livewire::test(LiveAgents::class)
        ->call('sortBy', 'name')
        ->call('$refresh')
        ->assertSet('sort', 'name')
        ->assertSet('sortDirection', 'asc');
});

// --- LB-6: the Stuck tab — screens that went quiet while still claiming to work ---

/**
 * A board row with the heartbeat stamped by hand, so a test can sit an agent exactly
 * where the Stuck rule cares about: quiet-but-recent, quiet-and-ancient, or never
 * punched at all. Call inside a tenant context.
 */
function quietAgent(string $name, PresenceStatus $status, ?int $quietMinutes): User
{
    $user = User::factory()->create(['name' => $name]);

    AgentPresence::factory()->forUser($user)->status($status)->create([
        'last_seen_at' => $quietMinutes === null ? null : now()->subMinutes($quietMinutes),
    ]);

    return $user;
}

it('puts a gone-quiet agent on the Stuck tab and nowhere else', function () {
    $tenant = Tenant::factory()->create();

    [$rows, $stuck, $tabs] = TenantContext::run($tenant->id, function (): array {
        floorAgent('Working Wendy', PresenceStatus::Ready);
        quietAgent('Quiet Qasim', PresenceStatus::OnBreak, quietMinutes: 20);

        $page = new LiveAgents;
        $rows = $page->roster();
        $stuck = $page->stuckRoster();

        return [$rows, $stuck, $page->tabs($rows, count($stuck))];
    });

    expect(array_column($rows, 'name'))->toBe(['Working Wendy'])
        ->and(array_column($stuck, 'name'))->toBe(['Quiet Qasim'])
        ->and($stuck[0]['statusLabel'])->toBe('On break')   // what the board still claims
        ->and($stuck[0]['quietForMinutes'])->toBe(20)
        ->and($tabs[0])->toBe(['key' => 'floor', 'label' => 'On the floor', 'count' => 1]);
});

it('leaves off anyone whose screen went quiet more than 12 hours ago', function () {
    $tenant = Tenant::factory()->create();

    $stuck = TenantContext::run($tenant->id, function (): array {
        quietAgent('Yesterday Yash', PresenceStatus::Ready, quietMinutes: 13 * 60);
        quietAgent('This Shift Shweta', PresenceStatus::Ready, quietMinutes: 11 * 60);

        return (new LiveAgents)->stuckRoster();
    });

    expect(array_column($stuck, 'name'))->toBe(['This Shift Shweta']);
});

it('leaves off an agent who never punched at all', function () {
    $tenant = Tenant::factory()->create();

    $stuck = TenantContext::run($tenant->id, function (): array {
        quietAgent('Never Neha', PresenceStatus::Ready, quietMinutes: null);

        return (new LiveAgents)->stuckRoster();
    });

    expect($stuck)->toBeEmpty();
});

it('leaves off an agent who logged out properly', function () {
    $tenant = Tenant::factory()->create();

    $stuck = TenantContext::run($tenant->id, function (): array {
        quietAgent('Went Home Gita', PresenceStatus::Offline, quietMinutes: 90);

        return (new LiveAgents)->stuckRoster();
    });

    expect($stuck)->toBeEmpty();
});

it("never shows another client's stuck agents", function () {
    $clientA = Tenant::factory()->create();
    $clientB = Tenant::factory()->create();

    TenantContext::run($clientA->id, fn () => quietAgent('A Quiet', PresenceStatus::Ready, 20));
    TenantContext::run($clientB->id, fn () => quietAgent('B Quiet', PresenceStatus::Ready, 20));

    $stuck = TenantContext::run($clientA->id, fn (): array => (new LiveAgents)->stuckRoster());

    expect(array_column($stuck, 'name'))->toBe(['A Quiet']);
});

it('renders the Stuck tab with what the board still claims', function () {
    $tenant = Tenant::factory()->create();
    $leader = clientUserWithRole($tenant, RoleName::TeamLeader->value);

    TenantContext::run($tenant->id, function () {
        floorAgent('Working Wendy', PresenceStatus::Ready);
        quietAgent('Quiet Qasim', PresenceStatus::OnCall, quietMinutes: 20);
    });

    $this->actingAs($leader);
    TenantContext::applyWebRequest($tenant->id, crossTenant: false);

    Livewire::test(LiveAgents::class)
        ->assertSee('Working Wendy')
        ->assertDontSee('Quiet Qasim')
        ->set('tab', 'stuck')
        ->assertSee('Quiet Qasim')
        ->assertSee('Board still says')
        ->assertDontSee('Working Wendy');
});

// --- LB-8 / LB-12 / LB-15: forcing a stuck agent offline ---

it('offers the log-out button to team leaders and global staff, and to nobody else', function (string $role, bool $expected) {
    $tenant = Tenant::factory()->create();

    $viewer = in_array($role, RoleName::globalValues(), strict: true)
        ? reportsHcUser($role)
        : clientUserWithRole($tenant, $role);

    $this->actingAs($viewer->fresh());
    TenantContext::applyWebRequest(
        $viewer->operatesGlobally() ? null : $tenant->id,
        crossTenant: $viewer->operatesGlobally(),
    );

    expect((new LiveAgents)->canForceLogOut())->toBe($expected);
})->with([
    'team leader' => [RoleName::TeamLeader->value, true],
    'super admin' => [RoleName::SuperAdmin->value, true],
    'ops manager' => [RoleName::OpsManager->value, true],
    'QC' => [RoleName::Qc->value, false],
    'trainer' => [RoleName::Trainer->value, false],
    'client user' => [RoleName::ClientUser->value, false],
]);

it('offers no log-out button on a row the board still shows on a call', function () {
    $tenant = Tenant::factory()->create();

    $stuck = TenantContext::run($tenant->id, function (): array {
        quietAgent('Mid Call Meera', PresenceStatus::OnCall, quietMinutes: 20);
        quietAgent('Ready Rita', PresenceStatus::Ready, quietMinutes: 20);

        return (new LiveAgents)->stuckRoster();
    });

    expect(collect($stuck)->pluck('canLogOut', 'name')->all())
        ->toBe(['Mid Call Meera' => false, 'Ready Rita' => true]);
});

it('refuses to log anyone out for a viewer who may not', function () {
    $tenant = Tenant::factory()->create();
    $qc = clientUserWithRole($tenant, RoleName::Qc->value);
    $agent = TenantContext::run($tenant->id, fn (): User => quietAgent('Quiet Qasim', PresenceStatus::Ready, 20));

    $this->actingAs($qc->fresh());
    TenantContext::applyWebRequest($tenant->id, crossTenant: false);

    expect(fn () => (new LiveAgents)->forceLogOut($agent->id))
        ->toThrow(HttpException::class);

    TenantContext::run($tenant->id, function () use ($agent) {
        expect(AgentPresence::query()->where('user_id', $agent->id)->first()->status)
            ->toBe(PresenceStatus::Ready); // untouched
    });
});

it('closes a stuck agent\'s diary entry when their screen really had died, not at the click', function () {
    $tenant = Tenant::factory()->create();
    $leader = clientUserWithRole($tenant, RoleName::TeamLeader->value);

    $agent = TenantContext::run($tenant->id, function (): User {
        $user = quietAgent('Quiet Qasim', PresenceStatus::OnBreak, quietMinutes: 20);
        AgentStatusHistory::factory()->forUser($user)->status(PresenceStatus::OnBreak)
            ->create(['started_at' => now()->subMinutes(50)]);

        return $user;
    });

    $this->actingAs($leader);
    TenantContext::applyWebRequest($tenant->id, crossTenant: false);

    (new LiveAgents)->forceLogOut($agent->id);

    TenantContext::run($tenant->id, function () use ($agent) {
        $presence = AgentPresence::query()->where('user_id', $agent->id)->first();
        $stint = AgentStatusHistory::query()->where('user_id', $agent->id)->first();

        expect($presence->status)->toBe(PresenceStatus::Offline)
            ->and($stint->ended_via)->toBe(StintEndedVia::Stale)
            // last punch (20 min ago) + the 120-second stale window — the moment the
            // screen actually died, never 20 minutes later when somebody noticed.
            ->and($stint->ended_at->diffInSeconds(now()->subMinutes(20)->addSeconds(120)))
            ->toBeLessThan(2)
            // Offline opens no new stint: in history, offline is the gap between them.
            ->and(AgentStatusHistory::query()->where('user_id', $agent->id)->count())->toBe(1);
    });
});

// The other half of LB-15, and the case the Stuck tab can never catch: the agent went
// home and left the machine on. Their screen keeps punching, so the board keeps them
// Ready and the router keeps ringing a dead desk. The entry closes NOW, reason forced.

it('closes the diary entry at the click when the screen was still answering', function () {
    $tenant = Tenant::factory()->create();
    $leader = clientUserWithRole($tenant, RoleName::TeamLeader->value);

    $agent = TenantContext::run($tenant->id, fn (): User => floorAgent('Gone Home Gaurav', PresenceStatus::Ready, sinceMinutes: 130));

    $this->actingAs($leader);
    TenantContext::applyWebRequest($tenant->id, crossTenant: false);

    (new LiveAgents)->forceLogOut($agent->id);

    TenantContext::run($tenant->id, function () use ($agent) {
        $stint = AgentStatusHistory::query()->where('user_id', $agent->id)->first();

        expect(AgentPresence::query()->where('user_id', $agent->id)->first()->status)
            ->toBe(PresenceStatus::Offline)
            ->and($stint->ended_via)->toBe(StintEndedVia::Forced)
            ->and($stint->ended_at->diffInSeconds(now()))->toBeLessThan(2);
    });
});

it('refuses to log out an agent who is on a call', function () {
    $tenant = Tenant::factory()->create();
    $leader = clientUserWithRole($tenant, RoleName::TeamLeader->value);

    $agent = TenantContext::run($tenant->id, function (): User {
        $rows = (new LiveAgents)->roster();
        expect($rows)->toBeEmpty();

        return floorAgent('Mid Call Meera', PresenceStatus::OnCall);
    });

    $this->actingAs($leader);
    TenantContext::applyWebRequest($tenant->id, crossTenant: false);

    $page = new LiveAgents;

    expect($page->roster()[0]['canLogOut'])->toBeFalse(); // no button drawn…

    $page->forceLogOut($agent->id);                       // …and the method refuses too

    TenantContext::run($tenant->id, function () use ($agent) {
        expect(AgentPresence::query()->where('user_id', $agent->id)->first()->status)
            ->toBe(PresenceStatus::OnCall)
            ->and(ActivityLog::query()->where('event', 'forced_offline')->exists())->toBeFalse();
    });
});

it('writes an audit note naming who logged whom out', function () {
    $tenant = Tenant::factory()->create();
    $leader = clientUserWithRole($tenant, RoleName::TeamLeader->value);
    $agent = TenantContext::run($tenant->id, fn (): User => quietAgent('Quiet Qasim', PresenceStatus::Ready, 20));

    $this->actingAs($leader);
    TenantContext::applyWebRequest($tenant->id, crossTenant: false);

    (new LiveAgents)->forceLogOut($agent->id);

    $note = TenantContext::run($tenant->id, fn () => ActivityLog::query()->where('event', 'forced_offline')->first());

    expect($note)->not->toBeNull()
        ->and($note->causer_id)->toBe($leader->id)
        ->and($note->tenant_id)->toBe($tenant->id)
        ->and($note->properties['agent_id'])->toBe($agent->id)
        ->and($note->properties['agent_name'])->toBe('Quiet Qasim');
});

// The LB-15 subject fix. Our own global staff sit in NO client, so a note filed
// against the agent (the users table has no client column) would be filed nowhere and
// nobody inside that client could ever see it happened. Filed against the board row,
// which is client-owned, it lands where it belongs.

it("keeps the agent's client on the audit note when global staff press the button", function () {
    $tenant = Tenant::factory()->create();
    $admin = reportsHcUser(RoleName::SuperAdmin->value);
    $agent = TenantContext::run($tenant->id, fn (): User => quietAgent('Quiet Qasim', PresenceStatus::Ready, 20));

    $this->actingAs($admin->fresh());
    TenantContext::applyWebRequest(null, crossTenant: true);

    (new LiveAgents)->forceLogOut($agent->id);

    $note = ActivityLog::query()->where('event', 'forced_offline')->first();

    expect($note->tenant_id)->toBe($tenant->id)
        ->and($note->causer_id)->toBe($admin->id);
});

it('leaves alone an agent who is already offline', function () {
    $tenant = Tenant::factory()->create();
    $leader = clientUserWithRole($tenant, RoleName::TeamLeader->value);
    $agent = TenantContext::run($tenant->id, fn (): User => quietAgent('Went Home Gita', PresenceStatus::Offline, 90));

    $this->actingAs($leader);
    TenantContext::applyWebRequest($tenant->id, crossTenant: false);

    (new LiveAgents)->forceLogOut($agent->id);

    TenantContext::run($tenant->id, function () {
        expect(ActivityLog::query()->where('event', 'forced_offline')->exists())->toBeFalse();
    });
});

// --- LB-4: the running clock behind the "For" column ---

it('hands the browser the moment each status began, and a readable fallback', function () {
    $tenant = Tenant::factory()->create();
    $leader = clientUserWithRole($tenant, RoleName::TeamLeader->value);

    TenantContext::run($tenant->id, function () {
        floorAgent('Ready Rita', PresenceStatus::Ready, sinceMinutes: 65);
    });

    $this->actingAs($leader);
    TenantContext::applyWebRequest($tenant->id, crossTenant: false);

    [$started, $stint] = TenantContext::run($tenant->id, fn (): array => [
        (new LiveAgents)->roster()[0]['startedAtMs'],
        AgentStatusHistory::query()->open()->first(),
    ]);

    // The stint's own start, to the millisecond — not a duration worked out on the
    // server, so a throttled background tab is right again the moment it is looked at.
    expect($started)->toBe($stint->started_at->getTimestampMs());

    Livewire::test(LiveAgents::class)
        ->assertSee("clock({$started})", escape: false) // the browser counts up from it
        ->assertSee('1h 5m');                           // and this shows if its JS never runs
});

// --- LB-13: the Client column, for our own global staff only ---

it('shows the Client column to global staff and hides it from a team leader', function () {
    $clientA = Tenant::factory()->create(['name' => 'Acme Support']);
    $clientB = Tenant::factory()->create(['name' => 'Bharat Telecom']);
    $leader = clientUserWithRole($clientA, RoleName::TeamLeader->value);
    $admin = reportsHcUser(RoleName::SuperAdmin->value);

    TenantContext::run($clientA->id, fn () => floorAgent('A Agent', PresenceStatus::Ready));
    TenantContext::run($clientB->id, fn () => floorAgent('B Agent', PresenceStatus::Ready));

    $this->actingAs($leader);
    TenantContext::applyWebRequest($clientA->id, crossTenant: false);
    $page = new LiveAgents;

    expect($page->showsClient())->toBeFalse()
        ->and($page->roster()[0]['client'])->toBeNull();

    $this->actingAs($admin->fresh());
    TenantContext::applyWebRequest(null, crossTenant: true);
    $page = new LiveAgents;
    $rows = $page->roster();

    expect($page->showsClient())->toBeTrue()
        ->and(collect($rows)->pluck('client', 'name')->all())
        ->toBe(['A Agent' => 'Acme Support', 'B Agent' => 'Bharat Telecom']);

    // Sorting by the column is how a global viewer groups the list by client (LB-13).
    $page->sortBy('client');
    $page->sortBy('client'); // and back the other way
    expect(array_column($page->visibleRows($rows), 'name'))->toBe(['B Agent', 'A Agent']);

    Livewire::test(LiveAgents::class)
        ->assertOk()
        ->assertSee('Client')
        ->assertSee('Acme Support')
        ->assertSee('Bharat Telecom');
});

// --- A real render: rows, status, and the over-the-limit marker reach the page ---

it('renders the live board with the head count and the over-the-limit marker', function () {
    $tenant = Tenant::factory()->create();
    $leader = clientUserWithRole($tenant, RoleName::TeamLeader->value);

    TenantContext::run($tenant->id, function () {
        $lunch = BreakCategory::factory()->withLimit(30)->create(['code' => 'LUNCH', 'label' => 'Lunch']);
        floorAgent('Asha', PresenceStatus::OnBreak, sinceMinutes: 45, break: $lunch);
        floorAgent('Ravi', PresenceStatus::Ready);
    });

    $this->actingAs($leader);
    TenantContext::applyWebRequest($tenant->id, crossTenant: false);

    Livewire::test(LiveAgents::class)
        ->assertOk()
        ->assertSee('Asha')
        ->assertSee('Ravi')
        ->assertSee('Lunch')
        ->assertSee('over the limit')
        ->assertSee('On the floor');
});
