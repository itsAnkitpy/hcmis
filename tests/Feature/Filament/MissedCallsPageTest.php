<?php

use App\Enums\CallDirection;
use App\Enums\CallOutcome;
use App\Enums\RoleName;
use App\Filament\Pages\MissedCalls;
use App\Models\Call;
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

/**
 * B2.3b-i QD-9 — the missed-call list: the callers who never got through, for an agent
 * to work back. The row is written by the listener (proven in MissedCallRecordTest);
 * this proves the screen shows the right ones, in the right order, to the right people.
 *
 * The definition of "never got through" is read off the row, not a flag — inbound, an
 * abandoned or unanswered outcome, and no agent on it. The last part is what keeps a
 * handled call that an agent dispositioned as a non-contact off this list.
 */
function missedCallsHcUser(string $role): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    Role::findOrCreate($role, 'web');
    $user->assignRole($role);

    return $user;
}

/** A listener-written missed-call row (no agent, no disposition — nobody handled it). */
function missedCall(array $attributes = []): Call
{
    return Call::factory()->create(array_merge([
        'direction' => CallDirection::Inbound,
        'outcome' => CallOutcome::Abandoned,
        'agent_id' => null,
        'disposition_id' => null,
        'campaign_id' => null,
        'lead_id' => null,
    ], $attributes));
}

it('lets an agent open the missed-call list', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $this->actingAs($agent)->get('/admin/missed-calls')->assertSuccessful();
});

it('lets a team leader open it too — the floor needs to see what is being missed', function () {
    $tenant = Tenant::factory()->create();
    $leader = clientUserWithRole($tenant, RoleName::TeamLeader->value);

    $this->actingAs($leader)->get('/admin/missed-calls')->assertSuccessful();
});

it('forbids the missed-call list for a client role with no call access', function () {
    $tenant = Tenant::factory()->create();
    $user = clientUserWithRole($tenant, RoleName::ClientUser->value);

    $this->actingAs($user)->get('/admin/missed-calls')->assertForbidden();
});

it('shows the callers nobody reached, newest first (QD-9)', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    TenantContext::run($tenant->id, function (): void {
        missedCall(['from_number' => '111', 'created_at' => now()->subHours(2)]);
        missedCall(['from_number' => '222', 'outcome' => CallOutcome::NoAnswer, 'created_at' => now()->subMinutes(5)]);
    });

    $this->actingAs($agent);

    TenantContext::run($tenant->id, function (): void {
        $rows = (new MissedCalls)->missedCalls();

        // Newest first: someone who rang five minutes ago is still interested now.
        expect($rows->pluck('from_number')->all())->toBe(['222', '111']);
    });
});

it('leaves out calls somebody actually handled, and outbound calls entirely', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    TenantContext::run($tenant->id, function () use ($agent): void {
        missedCall(['from_number' => 'missed']);

        // Handled, then marked as a non-contact — it carries `no_answer` AND an agent,
        // and the agent is what keeps it off this list.
        Call::factory()->forAgent($agent)->create([
            'direction' => CallDirection::Inbound,
            'outcome' => CallOutcome::NoAnswer,
            'from_number' => 'handled',
        ]);

        // An outbound attempt nobody picked up is the dialer's business, not this list's.
        Call::factory()->forAgent($agent)->create([
            'direction' => CallDirection::Outbound,
            'outcome' => CallOutcome::NoAnswer,
            'to_number' => 'outbound',
        ]);
    });

    $this->actingAs($agent);

    TenantContext::run($tenant->id, function (): void {
        expect((new MissedCalls)->missedCalls()->pluck('from_number')->all())->toBe(['missed']);
    });
});

it('never shows another client\'s missed calls', function () {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    TenantContext::run($tenant->id, fn () => missedCall(['from_number' => 'ours']));
    TenantContext::run($other->id, fn () => missedCall(['from_number' => 'theirs']));

    $this->actingAs($agent);

    TenantContext::run($tenant->id, function (): void {
        expect((new MissedCalls)->missedCalls()->pluck('from_number')->all())->toBe(['ours']);
    });
});

it('says how each caller was lost in plain words (QD-6)', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function (): void {
        $page = new MissedCalls;

        expect($page->reasonFor(missedCall(['outcome' => CallOutcome::Abandoned])))->toBe('They gave up waiting')
            ->and($page->reasonFor(missedCall(['outcome' => CallOutcome::NoAnswer])))->toBe('We stopped waiting');
    });
});
