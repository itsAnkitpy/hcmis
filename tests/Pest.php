<?php

use App\Enums\CallDirection;
use App\Enums\CallOutcome;
use App\Models\Call;
use App\Models\Campaign;
use App\Models\Disposition;
use App\Models\PhoneNumber;
use App\Models\Tenant;
use App\Models\User;
use App\Telephony\AgentRouter;
use App\Telephony\NumberDirectory;
use App\Telephony\TelephonyProvider;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Prefix of the synthetic dialled numbers the telephony tests route with. */
const TEST_DIALLED_PREFIX = '+1555000';

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

    // B2.3a ND-3: the front-door dialplan notes WHICH NUMBER WAS DIALLED on every
    // inbound call and the app looks up its owner (it used to stamp one hardcoded
    // company — RD-1 — which is why a second client could never have a number).
    // The parameter still means "which company is this call for"; it now travels as
    // a number this call arrived on. Pass tenantId: null to model a call on a number
    // we do not know or that is switched off (the ND-4 clean-end branch).
    if ($tenantId !== null) {
        $channel['channelvars'] = ['dialednumber' => dialledNumberForTenant($tenantId)];
    }

    return ['type' => 'StasisStart', 'args' => $args, 'channel' => $channel];
}

/** The synthetic dialled number the fake NumberDirectory maps back to a company. */
function dialledNumberForTenant(string|int $tenantId): string
{
    return TEST_DIALLED_PREFIX.$tenantId;
}

/**
 * An inbound call arriving on a REAL number (B2.3a): same shape as stasisStart's
 * inbound case, but carrying a number the real NumberDirectory can look up in the
 * phone_numbers table rather than a synthetic one the stub decodes.
 *
 * @return array<string, mixed>
 */
function inboundOn(string $legId, string $dialledNumber, ?string $callerNumber = null): array
{
    $event = stasisStart($legId, [], $callerNumber);
    $event['channel']['channelvars']['dialednumber'] = $dialledNumber;

    return $event;
}

/**
 * Bind a stub NumberDirectory (B2.3a) that resolves the synthetic numbers
 * stasisStart() stamps, without touching the database. Same reasoning as
 * fakeAgentRouter: these tests prove call MECHANICS, and the real number -> client
 * lookup (RLS, switched-off rows, cross-client isolation) is proven against the DB
 * in NumberDirectoryTest.
 */
function fakeNumberDirectory(): NumberDirectory
{
    $directory = new class extends NumberDirectory
    {
        public function resolve(?string $dialledNumber): ?PhoneNumber
        {
            if ($dialledNumber === null || ! str_starts_with($dialledNumber, TEST_DIALLED_PREFIX)) {
                return null;
            }

            return (new PhoneNumber)->forceFill([
                'tenant_id' => (int) substr($dialledNumber, strlen(TEST_DIALLED_PREFIX)),
            ]);
        }
    };

    app()->instance(NumberDirectory::class, $directory);

    return $directory;
}

/**
 * Bind a stub AgentRouter (B2.2b) that always reserves the given agent id (or null
 * for "all busy"), recording every reserve/release. Lets the flow + switchboard tests
 * stay focused on call MECHANICS — the real board read + atomic reserve/release are
 * proven against the DB in AgentRouterTest. Returns the stub for assertions.
 *
 * B2.3b-i QD-4: it honours the per-call skip list the same way the real board does —
 * an agent this caller has already been rung out on is not handed back again — and
 * records every skip list it was asked with, so a waiting-room test can prove the
 * flow is actually passing one.
 */
function fakeAgentRouter(?int $agentId = 6): AgentRouter
{
    $router = new class($agentId) extends AgentRouter
    {
        /** @var array<int, int> */
        public array $reserved = [];

        /** @var array<int, array<int, int>> */
        public array $skipped = [];

        /** @var array<int, array{int, int}> */
        public array $released = [];

        /** Public and mutable so a waiting-room test can free an agent up mid-test. */
        public function __construct(public ?int $agentId) {}

        public function reserveFreeAgent(int $tenantId, array $skipUserIds = []): ?int
        {
            $this->reserved[] = $tenantId;
            $this->skipped[] = $skipUserIds;

            if ($this->agentId !== null && in_array($this->agentId, $skipUserIds, strict: true)) {
                return null;   // this caller has already been rung out on our one agent
            }

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
 * A mocked phone system for tests that are not ABOUT the hold music (S88).
 *
 * Since the music now runs from the moment we answer right through to the moment the
 * caller and agent are joined, every inbound call touches those two verbs — including
 * the transfer, conference and handoff tests, none of which care. This declares them
 * as "may happen, any number of times" so those tests stay about their own subject.
 *
 * Tests that ARE about the music (CallToAgentFlowWaitingRoomTest, SwitchboardTest's
 * sweep cases) build their own strict mock instead, so the counts stay real there.
 *
 * @return TelephonyProvider&MockInterface
 */
function fakeTelephony(): MockInterface
{
    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('startHoldMusic')->zeroOrMoreTimes();
    $telephony->shouldReceive('stopHoldMusic')->zeroOrMoreTimes();

    return $telephony;
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
 * The OTHER way a leg ending arrives — "this leg has left the app" (S87, found live).
 * It is the one an outside caller's own hang-up produces, and for a long time nothing
 * listened for it. Same meaning as channelDestroyed(); different word from the engine.
 *
 * @return array<string, mixed>
 */
function stasisEnd(string $legId): array
{
    return ['type' => 'StasisEnd', 'channel' => ['id' => $legId]];
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
