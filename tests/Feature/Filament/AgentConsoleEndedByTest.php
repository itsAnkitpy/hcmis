<?php

use App\Enums\CallEndedBy;
use App\Enums\RoleName;
use App\Filament\Pages\AgentConsole;
use App\Models\Call;
use App\Models\CallHandoff;
use App\Models\Campaign;
use App\Models\Disposition;
use App\Models\Lead;
use App\Models\PhoneNumber;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * call-export.md CE-6 + CE-11, the console's half. The listener leaves the dialled
 * number and who-hung-up on the handoff note; the wrap-up copies them onto the row and
 * looks the campaign up from the number.
 *
 * The listener's half is in tests/Feature/Telephony/CallEndedByAndDialledNumberTest.php.
 */
uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::forget();
});

beforeEach(function () {
    Http::fake(['*' => Http::response(['id' => 'agent-leg'])]);
});

/**
 * An inbound call already claimed by this console: a note carrying the given fields,
 * and a console holding that same ticket. Local to this file rather than borrowed from
 * AgentConsoleCallTimingTest, so running this file alone still works.
 *
 * @param  array<string, mixed>  $note
 */
function consoleWithNote(Tenant $tenant, User $agent, array $note): AgentConsole
{
    $ticket = (string) Str::uuid();

    TenantContext::run($tenant->id, fn () => CallHandoff::factory()
        ->forAgent($agent)
        ->ticket($ticket)
        ->create($note));

    $page = new AgentConsole;
    $page->callCorrelationId = $ticket;
    $page->callPartyNumber = '9991234567';

    return $page;
}

/** The one row this client has. */
function theCall(Tenant $tenant): ?Call
{
    return TenantContext::run($tenant->id, fn (): ?Call => Call::query()->latest('id')->first());
}

// CE-11. The value travels exactly as the four timing moments do — same note, same
// ticket match, same copy at Done.
it('copies who hung up from the note onto the call row', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $this->actingAs($agent);

    $page = consoleWithNote($tenant, $agent, [
        'arrived_at' => now()->subMinutes(3),
        'answered_at' => now()->subMinutes(2),
        'ended_at' => now()->subMinute(),
        'ended_by' => CallEndedBy::Customer,
    ]);

    TenantContext::run($tenant->id, fn () => $page->completeUnmatched());

    expect(theCall($tenant)->ended_by)->toBe(CallEndedBy::Customer);
});

// CE-4's honesty rule. A call torn down by an error has no side that hung up, and a
// blank says so — the alternative is inventing one.
it('leaves who hung up blank when the note never carried it', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $this->actingAs($agent);

    $page = consoleWithNote($tenant, $agent, ['arrived_at' => now()->subMinute()]);

    TenantContext::run($tenant->id, fn () => $page->completeUnmatched());

    expect(theCall($tenant)->ended_by)->toBeNull();
});

// CE-6, and the reason it exists: an inbound caller who is not in the system matches no
// lead, so before this every one of those rows exported a blank Campaign — which on a
// real floor is most inbound calls.
it('fills the campaign and our own number from the number the caller rang', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $this->actingAs($agent);

    $campaign = TenantContext::run($tenant->id, function (): Campaign {
        $campaign = Campaign::factory()->create();
        PhoneNumber::factory()->create(['number' => '+911772345678', 'campaign_id' => $campaign->id]);

        return $campaign;
    });

    $page = consoleWithNote($tenant, $agent, [
        'arrived_at' => now()->subMinute(),
        'dialled_number' => '+911772345678',
    ]);

    TenantContext::run($tenant->id, fn () => $page->completeUnmatched());

    $call = theCall($tenant);

    expect($call->campaign_id)->toBe($campaign->id)
        // "Our number" on an inbound call is the one they rang — blank until CE-6.
        ->and($call->to_number)->toBe('+911772345678');
});

// CE-6 narrows the hole; it does not close it. `phone_numbers.campaign_id` is nullable,
// so a number nobody has assigned to a campaign still exports a blank Campaign — and
// the §8 test for that blank therefore covers two causes, not one.
it('still leaves the campaign blank when the number belongs to no campaign', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $this->actingAs($agent);

    TenantContext::run($tenant->id, fn () => PhoneNumber::factory()
        ->create(['number' => '+911772345678', 'campaign_id' => null]));

    $page = consoleWithNote($tenant, $agent, [
        'arrived_at' => now()->subMinute(),
        'dialled_number' => '+911772345678',
    ]);

    TenantContext::run($tenant->id, fn () => $page->completeUnmatched());

    expect(theCall($tenant)->campaign_id)->toBeNull();
});

// The matched lead is the more specific fact — CE-6 only fills the gap where there is
// no lead at all, and must never override.
it('prefers the matched lead\'s campaign over the number\'s', function () {
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $this->actingAs($agent);

    [$leadCampaign, $lead, $disposition] = TenantContext::run($tenant->id, function (): array {
        $leadCampaign = Campaign::factory()->create(['name' => 'The lead\'s campaign']);
        $numberCampaign = Campaign::factory()->create(['name' => 'The number\'s campaign']);
        PhoneNumber::factory()->create(['number' => '+911772345678', 'campaign_id' => $numberCampaign->id]);

        return [
            $leadCampaign,
            Lead::factory()->create(['campaign_id' => $leadCampaign->id]),
            Disposition::factory()->create(['campaign_id' => $leadCampaign->id]),
        ];
    });

    $page = consoleWithNote($tenant, $agent, [
        'arrived_at' => now()->subMinute(),
        'dialled_number' => '+911772345678',
    ]);
    $page->matchedLeadId = $lead->id;

    TenantContext::run($tenant->id, fn () => $page->saveWrapUp($disposition->id));

    expect(theCall($tenant)->campaign_id)->toBe($leadCampaign->id);
});
