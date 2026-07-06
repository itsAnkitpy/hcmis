<?php

use App\Enums\CallbackStatus;
use App\Enums\RoleName;
use App\Filament\Pages\MyDay;
use App\Models\Call;
use App\Models\Callback;
use App\Models\Disposition;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::resetWebRequest();
    TenantContext::forget();
});

/** An HC user holding a global role at the reserved global team. */
function myDayHcUser(string $role): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    Role::findOrCreate($role, 'web');
    $user->assignRole($role);

    return $user;
}

// --- MD-2 (as amended): the gate — Agent role ONLY ---

it('lets an agent open My Day', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $this->actingAs($agent)->get('/admin/my-day')->assertSuccessful();
});

it('forbids My Day for every non-agent client role', function (string $role) {
    $tenant = Tenant::factory()->create();
    $user = clientUserWithRole($tenant, $role);

    $this->actingAs($user)->get('/admin/my-day')->assertForbidden();
})->with([
    'team leader' => [RoleName::TeamLeader->value],
    'qc' => [RoleName::Qc->value],
    'trainer' => [RoleName::Trainer->value],
    'client user' => [RoleName::ClientUser->value],
]);

it('forbids My Day for global staff — the page is self-scoped (MD-2)', function () {
    // Tighter than AgentConsole's gate on purpose: a global staffer would only
    // ever see an empty scoreboard; managers have the Agent Productivity report.
    $admin = myDayHcUser(RoleName::SuperAdmin->value);

    $this->actingAs($admin)->get('/admin/my-day')->assertForbidden();
});

// --- MD-3: the numbers — my calls, today, inside my client, nothing else ---

it('counts only my calls, today, inside my client (MD-3)', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $colleague = clientUserWithRole($tenant, RoleName::Agent->value);

    TenantContext::run($tenant->id, function () use ($agent, $colleague): void {
        $sold = Disposition::factory()->sale()->create(['label' => 'Sold']);
        $noAnswer = Disposition::factory()->create(['label' => 'No answer', 'is_contact' => false, 'is_sale' => false]);

        // Mine, today: one sale + one no-answer.
        Call::factory()->forAgent($agent)->answered()->create(['disposition_id' => $sold->id]);
        Call::factory()->forAgent($agent)->noAnswer()->create(['disposition_id' => $noAnswer->id]);

        // Excluded: my call YESTERDAY, and a colleague's call today.
        Call::factory()->forAgent($agent)->answered()->create(['disposition_id' => $sold->id, 'created_at' => now()->subDay()]);
        Call::factory()->forAgent($colleague)->answered()->create(['disposition_id' => $sold->id]);
    });

    // Excluded by the tenant wall: another client's call carrying MY agent id.
    $otherTenant = Tenant::factory()->create();
    TenantContext::run($otherTenant->id, fn (): Call => Call::factory()->forAgent($agent)->create());

    $this->actingAs($agent);

    TenantContext::run($tenant->id, function () use ($agent): void {
        $page = new MyDay;

        expect($page->tiles())->toMatchArray([
            'total' => 2,
            'contacts' => 1,
            'sales' => 1,
            'no_answer' => 1,
            'contact_rate' => 50.0,
        ]);

        $outcomes = $page->outcomes();
        expect($outcomes)->toHaveCount(2)
            ->and(collect($outcomes)->firstWhere('label', 'Sold'))->toMatchArray(['count' => 1, 'percentage' => 50.0])
            ->and(collect($outcomes)->firstWhere('label', 'No answer'))->toMatchArray(['count' => 1, 'percentage' => 50.0]);

        $calls = $page->myCalls();
        expect($calls)->toHaveCount(2)
            ->and($calls->pluck('agent_id')->unique()->all())->toBe([$agent->id]);
    });
});

// --- MD-3: callbacks due — pending, due by end of today, overdue included ---

it('counts pending callbacks due by end of today — overdue in; done, future and other-agent out (MD-3)', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $colleague = clientUserWithRole($tenant, RoleName::Agent->value);

    TenantContext::run($tenant->id, function () use ($agent, $colleague): void {
        // In: due earlier today + overdue from yesterday.
        Callback::factory()->forAgent($agent)->status(CallbackStatus::Pending)->due()->create();
        Callback::factory()->forAgent($agent)->status(CallbackStatus::Pending)->create(['scheduled_at' => now()->subDay()]);

        // Out: tomorrow's, an already-done one, and a colleague's.
        Callback::factory()->forAgent($agent)->status(CallbackStatus::Pending)->create(['scheduled_at' => now()->addDay()]);
        Callback::factory()->forAgent($agent)->status(CallbackStatus::Done)->due()->create();
        Callback::factory()->forAgent($colleague)->status(CallbackStatus::Pending)->due()->create();
    });

    $this->actingAs($agent);

    TenantContext::run($tenant->id, function (): void {
        expect((new MyDay)->callbacksDue())->toBe(2);
    });
});

// --- MD-4: the player renders only where a recording exists, and never a download ---

it('renders the in-page player only for calls with a recording, with no download link (MD-4)', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $recorded = TenantContext::run(
        $tenant->id,
        fn (): Call => Call::factory()->forAgent($agent)->withRecording()->create(),
    );
    TenantContext::run($tenant->id, fn (): Call => Call::factory()->forAgent($agent)->create());

    $this->actingAs($agent)
        ->get('/admin/my-day')
        ->assertSuccessful()
        ->assertSee('<audio', false)
        ->assertSee(route('calls.recording', $recorded), false)
        // MD-4 as amended: no sanctioned download link, and the audio element
        // carries no native controls (whose browser menu offers a Download item);
        // controlsList guards the menu should native controls ever return.
        ->assertDontSee('download=1', false)
        ->assertDontSee('<audio controls', false)
        ->assertSee('controlsList="nodownload"', false);
});

it('shows the empty state, not a player, when I have no recorded calls', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $this->actingAs($agent)
        ->get('/admin/my-day')
        ->assertSuccessful()
        ->assertSee('No calls yet today.')
        ->assertDontSee('<audio', false);
});
