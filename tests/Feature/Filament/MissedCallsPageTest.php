<?php

use App\Enums\CallDirection;
use App\Enums\CallOutcome;
use App\Enums\MissedReason;
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

it('shows an hour-long wait as an hour, not as an instant hang-up (S88 review #6)', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function (): void {
        $page = new MissedCalls;

        // The hour used to be thrown away, so the person who held longest of all appeared
        // on this screen as the one who hung up straight away.
        //
        // CT-4: the figures are unchanged, only their source is. They are now computed
        // from arrival -> the moment they were lost, rather than read from the retired
        // `duration_seconds` — the same column the Calls list labelled "Duration".
        $waited = fn (int $seconds): Call => missedCall([
            'started_at' => now()->subSeconds($seconds),
            'ended_at' => now(),
        ]);

        expect($page->waitedFor($waited(3725)))->toBe('01:02:05')
            ->and($page->waitedFor($waited(125)))->toBe('02:05')
            ->and($page->waitedFor(missedCall(['started_at' => null, 'ended_at' => null])))->toBe('—');
    });
});

it('keeps a caller the menu served off the list, and everyone else on it (AUQ-3)', function () {
    // 🔴 THIS ASSERTS THE SCREEN'S OWN QUERY, NOT THE ENUM. A test that only asked the
    // reason whether it belongs here would prove our belief about it and nothing else —
    // the list is built from the outcome and this branch is what excludes them.
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function (): void {
        $gaveUp = missedCall(['outcome' => CallOutcome::Abandoned]);
        $weStopped = missedCall(['outcome' => CallOutcome::NoAnswer]);
        $closed = missedCall(['outcome' => CallOutcome::NoAnswer, 'missed_reason' => MissedReason::ClosedHours]);
        $hungUpInMenu = missedCall(['outcome' => CallOutcome::Abandoned, 'missed_reason' => MissedReason::HungUpInMenu]);
        // Served by the menu: they got what they rang for, so nobody rings them back.
        $served = missedCall(['outcome' => CallOutcome::NoAnswer, 'missed_reason' => MissedReason::ServedByMenu]);

        $shown = (new MissedCalls)->missedCalls()->pluck('id');

        // 🔴 The two with NO reason at all matter most here. In SQL `NULL NOT IN (…)` is
        // NULL, which is not true — so a bare NOT IN would have hidden every ordinary
        // missed call and emptied this screen entirely.
        expect($shown)->toContain($gaveUp->id)
            ->and($shown)->toContain($weStopped->id)
            ->and($shown)->toContain($closed->id)
            ->and($shown)->toContain($hungUpInMenu->id)
            ->and($shown)->not->toContain($served->id);
    });
});

it('says how each caller was lost in plain words (QD-6)', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function (): void {
        $page = new MissedCalls;

        expect($page->reasonFor(missedCall(['outcome' => CallOutcome::Abandoned])))->toBe('They gave up waiting')
            ->and($page->reasonFor(missedCall(['outcome' => CallOutcome::NoAnswer])))->toBe('We stopped waiting')
            // inbound-audio slice 1 (AU-3): the stored reason wins over the outcome's text.
            ->and($page->reasonFor(missedCall(['outcome' => CallOutcome::NoAnswer, 'missed_reason' => MissedReason::ClosedHours])))->toBe('Called while closed')
            // inbound-audio slice 6 (AU-26).
            ->and($page->reasonFor(missedCall(['outcome' => CallOutcome::Abandoned, 'missed_reason' => MissedReason::HungUpInMenu])))->toBe('Hung up in the menu');
    });
});
