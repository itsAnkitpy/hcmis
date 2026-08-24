<?php

use App\Enums\CallbackStatus;
use App\Enums\CallDirection;
use App\Enums\CallOutcome;
use App\Filament\Pages\AgentConsole;
use App\Models\Call;
use App\Models\Callback;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::forget();
});

/**
 * CH-2 / CH-1 (PRD/phase-2/customer-history-panel.md) — the ring-time answer to "have we
 * dealt with this number before, and how did it go?".
 *
 * The whole slice rests on keying the history on the NUMBER rather than on a customer
 * record, so these prove the four things that claim depends on: the client wall still
 * holds when the query starts from a phone number (which is not a tenant-scoped value on
 * its own), the customer's number is read from the right column in each direction, a
 * caller nobody ever saved still accumulates a history, and a callback we owe shows only
 * where one can exist.
 */

/** The panel as the blade reads it, in the agent's own client. */
function historyFor(int $tenantId, AgentConsole $console): array
{
    return TenantContext::run($tenantId, fn (): array => $console->callHistory());
}

/** A console already holding a live call to $number, the way a ring or a dial leaves it. */
function consoleOnCallTo(string $number, CallDirection $direction = CallDirection::Inbound, ?int $leadId = null): AgentConsole
{
    $console = new AgentConsole;
    $console->callPartyNumber = $number;
    $console->callDirection = $direction;
    $console->matchedLeadId = $leadId;

    return $console;
}

// CH-T1. The wall. This query has no tenant-scoped input at all — a phone number belongs
// to nobody — so RLS + BelongsToTenant are doing the whole job alone here. Named agents,
// asserted by identity: a count would pass just as happily on the wrong client's row.
it('never shows one client the history of the same number in another client', function () {
    $acme = Tenant::factory()->create();
    $globex = Tenant::factory()->create();

    TenantContext::run($acme->id, function (): void {
        $agent = User::factory()->create(['name' => 'Abhikesh']);
        Call::factory()->inbound()->forAgent($agent)->create(['from_number' => '9991234567']);
    });

    TenantContext::run($globex->id, function (): void {
        $agent = User::factory()->create(['name' => 'Priya']);
        Call::factory()->inbound()->forAgent($agent)->create(['from_number' => '9991234567']);
    });

    $acmeHistory = historyFor($acme->id, consoleOnCallTo('9991234567'));
    $globexHistory = historyFor($globex->id, consoleOnCallTo('9991234567'));

    expect(array_column($acmeHistory['calls'], 'agent'))->toBe(['Abhikesh'])
        ->and(array_column($globexHistory['calls'], 'agent'))->toBe(['Priya']);
});

// CH-T2. The customer's number is `to_number` on an outbound call and `from_number` on an
// inbound one — the other column holds OUR OWN line each time. Both halves are asserted,
// so a version with the two swapped fails; and our own number is asked for too, because a
// plain "either column matches" would read a client's own calls back as a customer's.
it('reads the customer number from the right column in each direction', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function (): void {
        $outbound = User::factory()->create(['name' => 'Dialer']);
        $inbound = User::factory()->create(['name' => 'Receiver']);

        Call::factory()->forAgent($outbound)->create([
            'direction' => CallDirection::Outbound,
            'from_number' => '18005550000',   // ours
            'to_number' => '9991234567',      // theirs
        ]);

        Call::factory()->inbound()->forAgent($inbound)->create([
            'from_number' => '9991234567',    // theirs
            'to_number' => '18005550000',     // ours
        ]);
    });

    $customer = historyFor($tenant->id, consoleOnCallTo('9991234567'));
    $ourOwnLine = historyFor($tenant->id, consoleOnCallTo('18005550000'));

    expect(array_column($customer['calls'], 'agent'))
        ->toEqualCanonicalizing(['Dialer', 'Receiver'])
        ->and($ourOwnLine['calls'])->toBe([]);
});

// CH-T3. The case the whole slicing argument rests on: an agent types a number nobody has
// ever saved, and the SECOND call to it has a history — with no customer record anywhere.
it('builds a history for an ad-hoc number that no customer record exists for', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function (): void {
        $agent = User::factory()->create(['name' => 'Abhikesh']);

        Call::factory()->forAgent($agent)->create([
            'direction' => CallDirection::Outbound,
            'to_number' => '9995550001',
            'lead_id' => null,          // the ad-hoc shape — nobody wrote this person down
            'campaign_id' => null,
            'disposition_id' => null,
            'outcome' => null,
        ]);

        expect(Lead::query()->count())->toBe(0);
    });

    $history = historyFor($tenant->id, consoleOnCallTo('9995550001', CallDirection::Outbound));

    expect($history['calls'])->toHaveCount(1)
        ->and($history['calls'][0]['agent'])->toBe('Abhikesh')
        ->and($history['calls'][0]['direction'])->toBe('Outbound')
        ->and($history['calls'][0]['outcome'])->toBeNull();   // no disposition on an ad-hoc wrap-up
});

// CH-T4. Callbacks are lead-keyed (`callbacks.lead_id` is NOT NULL), so they show for a
// matched customer and cannot exist for an unmatched number. A done one is not still owed.
it('shows only the callbacks still owed, and only for a customer we already know', function () {
    $tenant = Tenant::factory()->create();

    [$leadId, $doneAt] = TenantContext::run($tenant->id, function (): array {
        $campaign = Campaign::factory()->create();
        $lead = Lead::factory()->forCampaign($campaign)->create(['phone' => '9991234567']);

        Callback::factory()->forLead($lead)->create([
            'status' => CallbackStatus::Pending,
            'scheduled_at' => now()->addDay(),
            'notes' => 'Promised a call after lunch.',
        ]);

        $done = Callback::factory()->forLead($lead)->create([
            'status' => CallbackStatus::Done,
            'scheduled_at' => now()->addHours(2),
        ]);

        return [$lead->id, $done->scheduled_at];
    });

    $matched = historyFor($tenant->id, consoleOnCallTo('9991234567', CallDirection::Inbound, $leadId));
    $unmatched = historyFor($tenant->id, consoleOnCallTo('9991234567'));

    expect($matched['callbacks'])->toHaveCount(1)
        ->and($matched['callbacks'][0]['notes'])->toBe('Promised a call after lunch.')
        ->and($matched['callbacks'][0]['scheduledAtIso'])->not->toBe($doneAt->toIso8601String())
        // CH-4: no lead, no callback block — by construction, not by omission.
        ->and($unmatched['callbacks'])->toBe([]);
});

// CH-T6. The two honest empties: a number we have never called, and a caller with no
// number at all. Neither may fall back to reading the whole table.
it('returns nothing for a first-time number and for an anonymous caller', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function (): void {
        Call::factory()->create(['to_number' => '9990000000']);
    });

    $firstTime = historyFor($tenant->id, consoleOnCallTo('9995550002', CallDirection::Outbound));
    $anonymous = historyFor($tenant->id, new AgentConsole);   // no number held at all

    expect($firstTime['calls'])->toBe([])
        ->and($anonymous['calls'])->toBe([])
        ->and($anonymous['callbacks'])->toBe([]);
});

// CH-2 also caps what an agent reads mid-call: three lines, newest first. A longer list is
// a supervisor's tool (CH-3), and an agent reading a long table while a phone rings is
// reading nothing.
it('shows only the three most recent calls, newest first', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function (): void {
        foreach ([4, 3, 2, 1] as $daysAgo) {
            Call::factory()->create([
                'to_number' => '9991234567',
                'outcome' => CallOutcome::Answered,
                'created_at' => now()->subDays($daysAgo),
            ]);
        }
    });

    $history = historyFor($tenant->id, consoleOnCallTo('9991234567', CallDirection::Outbound));
    $when = array_column($history['calls'], 'whenIso');

    expect($when)->toHaveCount(3)
        ->and($when[0])->toBe(now()->subDay()->toIso8601String())
        ->and($when[2])->toBe(now()->subDays(3)->toIso8601String());
});

// CH-1's own guard, reached from real input: the Call Review filter (CH-3) passes whatever
// was typed, and a box holding only spaces is `filled()` but normalizes to nothing. Matching
// no rows is the only safe reading — the alternative is a blank box quietly listing every
// call the client has ever made as one customer's history.
it('matches no calls at all for a number that normalizes to nothing', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant->id, function (): void {
        Call::factory()->count(3)->create(['to_number' => '9991234567']);

        expect(Call::query()->forCustomerNumber('   ')->count())->toBe(0)
            ->and(Call::query()->forCustomerNumber(null)->count())->toBe(0);
    });
});
