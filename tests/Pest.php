<?php

use App\Enums\CallDirection;
use App\Enums\CallOutcome;
use App\Models\Call;
use App\Models\Campaign;
use App\Models\Disposition;
use App\Models\Tenant;
use App\Models\User;
use App\Telephony\AgentRouter;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * An HC user holding a GLOBAL role at the reserved global team (Super Admin /
 * HC Admin / Ops Manager) — no tenant membership, runs cross-tenant. Shared by
 * the Reporting feature tests (report Pages + dashboard widgets).
 */
function reportsHcUser(string $role): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    Role::findOrCreate($role, 'web');
    $user->assignRole($role);

    return $user;
}

/**
 * Create a user holding a per-client role scoped to the given tenant's team
 * (the spatie-teams posture). Shared by the Filament feature tests.
 */
function clientUserWithRole(Tenant $tenant, string $role): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->tenants()->attach($tenant);

    TenantContext::run($tenant->id, function () use ($user, $role) {
        Role::findOrCreate($role, 'web');
        $user->assignRole($role);
    });

    return $user;
}

/**
 * A known, deterministic call set inside one tenant, shared by the Reporting tests
 * (RP-1..RP-6). Five calls across two agents (Alice, Bob) and three dispositions —
 * a sale, a plain contact, a non-contact — plus one dispositionless ad-hoc call, so
 * every aggregate has a hand-computed expected value:
 *   total 5 · inbound 2 · outbound 3 · contacts 3 · sales 1 · no_answer(outcome) 2 ·
 *   with_recording 1. Alice: 3 calls (2 contacts, 1 sale, 1 rec). Bob: 2 (1 contact).
 *
 * @return array{alice: User, bob: User}
 */
function seedCallReportFixture(Tenant $tenant): array
{
    return TenantContext::run($tenant->id, function (): array {
        $alice = User::factory()->create(['name' => 'Alice']);
        $bob = User::factory()->create(['name' => 'Bob']);
        $campaign = Campaign::factory()->create();

        $sale = Disposition::factory()->sale()->create(['label' => 'Sold']);
        $contact = Disposition::factory()->create(['label' => 'Interested', 'is_contact' => true, 'is_sale' => false]);
        $noContact = Disposition::factory()->create(['label' => 'No answer', 'is_contact' => false, 'is_sale' => false]);

        $day = now();

        Call::factory()->forAgent($alice)->withRecording()->create([
            'direction' => CallDirection::Outbound, 'outcome' => CallOutcome::Answered,
            'campaign_id' => $campaign->id, 'disposition_id' => $sale->id, 'created_at' => $day,
        ]);
        Call::factory()->forAgent($alice)->create([
            'direction' => CallDirection::Outbound, 'outcome' => CallOutcome::Answered,
            'campaign_id' => $campaign->id, 'disposition_id' => $contact->id, 'created_at' => $day,
        ]);
        Call::factory()->forAgent($alice)->create([
            'direction' => CallDirection::Inbound, 'outcome' => CallOutcome::NoAnswer,
            'campaign_id' => $campaign->id, 'disposition_id' => $noContact->id, 'created_at' => $day,
        ]);

        Call::factory()->forAgent($bob)->create([
            'direction' => CallDirection::Inbound, 'outcome' => CallOutcome::Answered,
            'campaign_id' => $campaign->id, 'disposition_id' => $contact->id, 'created_at' => $day,
        ]);
        Call::factory()->forAgent($bob)->create([
            'direction' => CallDirection::Outbound, 'outcome' => CallOutcome::NoAnswer,
            'disposition_id' => null, 'created_at' => $day,
        ]);

        return ['alice' => $alice, 'bob' => $bob];
    });
}

/**
 * Write a simple CSV onto the (faked) local disk for the lead importer to read.
 * Shared by the M5 import tests.
 *
 * @param  array<int, array<int, string>>  $rows
 * @param  array<int, string>  $headers
 */
function writeLeadCsv(string $path, array $rows, array $headers = ['phone', 'name', 'email', 'region']): void
{
    $lines = [implode(',', $headers)];

    foreach ($rows as $row) {
        $lines[] = implode(',', $row);
    }

    Storage::disk('local')->put($path, implode("\n", $lines));
}

/*
| Raw ARI event shapes the telephony switchboard + flow read off the pipe. Only the
| fields the code actually inspects are built. Shared by CallToAgentFlowTest and
| SwitchboardTest (B2.1).
*/

/**
 * A leg entered our Stasis app. The `args` are the appArgs tag we placed it with
 * (e.g. [] = an outside caller, ['agent'] = the agent leg, ['snoop'] = a recording tap).
 *
 * @param  array<int, string>  $args
 * @return array<string, mixed>
 */
function stasisStart(string $legId, array $args, ?string $callerNumber = null, ?string $tenantId = '3'): array
{
    $channel = ['id' => $legId];

    if ($callerNumber !== null) {
        $channel['caller'] = ['number' => $callerNumber];
    }

    // B2.2b RD-1: the front-door dialplan stamps a company label (Asterisk's native
    // Tenant ID) on every inbound call, so the company-blind listener reads it off the
    // arrival event. Defaulted because real inbound calls always carry one; pass
    // tenantId: null to model an unlabelled call (the RD-5 no-company branch).
    if ($tenantId !== null) {
        $channel['tenantid'] = $tenantId;
    }

    return ['type' => 'StasisStart', 'args' => $args, 'channel' => $channel];
}

/**
 * Bind a stub AgentRouter (B2.2b) that always reserves the given agent id (or null
 * for "all busy"), recording every reserve/release. Lets the flow + switchboard tests
 * stay focused on call MECHANICS — the real board read + atomic reserve/release are
 * proven against the DB in AgentRouterTest. Returns the stub for assertions.
 */
function fakeAgentRouter(?int $agentId = 6): AgentRouter
{
    $router = new class($agentId) extends AgentRouter
    {
        /** @var array<int, int> */
        public array $reserved = [];

        /** @var array<int, array{int, int}> */
        public array $released = [];

        public function __construct(private readonly ?int $agentId) {}

        public function reserveFreeAgent(int $tenantId): ?int
        {
            $this->reserved[] = $tenantId;

            return $this->agentId;
        }

        public function releaseReservation(int $tenantId, int $userId): void
        {
            $this->released[] = [$tenantId, $userId];
        }
    };

    app()->instance(AgentRouter::class, $router);

    return $router;
}

/**
 * A leg ended (hang-up, no-answer timeout, or abandon).
 *
 * @return array<string, mixed>
 */
function channelDestroyed(string $legId): array
{
    return ['type' => 'ChannelDestroyed', 'channel' => ['id' => $legId]];
}

/**
 * One recording file finished writing.
 *
 * @return array<string, mixed>
 */
function recordingFinished(string $name): array
{
    return ['type' => 'RecordingFinished', 'recording' => ['name' => $name]];
}

/**
 * The web's control signal arriving on the event pipe (B2.4a TD-4). Mirrors the live
 * container shape verified against Asterisk 20.19.0: a source-less user-event arrives
 * as a `ChannelUserevent` with its name on `eventname` and the custom variables under
 * `userevent` (Asterisk also echoes `eventname` into that object — harmless).
 *
 * @param  array<string, string>  $variables
 * @return array<string, mixed>
 */
function channelUserevent(string $name, array $variables): array
{
    return [
        'type' => 'ChannelUserevent',
        'eventname' => $name,
        'userevent' => array_merge($variables, ['eventname' => $name]),
    ];
}
