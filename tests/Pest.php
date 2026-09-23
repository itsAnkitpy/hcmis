<?php

use App\Enums\CallDirection;
use App\Enums\CallOutcome;
use App\Models\Call;
use App\Models\Campaign;
use App\Models\Disposition;
use App\Models\PhoneNumber;
use App\Models\Tenant;
use App\Models\User;
use App\Telephony\AgentDirectory;
use App\Telephony\AgentPhoneWriter;
use App\Telephony\AgentRouter;
use App\Telephony\NumberDirectory;
use App\Telephony\TelephonyProvider;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
function fakeNumberDirectory(?int $menuId = null): NumberDirectory
{
    $directory = new class($menuId) extends NumberDirectory
    {
        /** inbound-audio AU-18: null is a number with no menu, which is every number before slice 6. */
        public function __construct(public ?int $menuId) {}

        public function resolve(?string $dialledNumber): ?PhoneNumber
        {
            if ($dialledNumber === null || ! str_starts_with($dialledNumber, TEST_DIALLED_PREFIX)) {
                return null;
            }

            return (new PhoneNumber)->forceFill([
                'tenant_id' => (int) substr($dialledNumber, strlen(TEST_DIALLED_PREFIX)),
                'menu_id' => $this->menuId,
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

        /**
         * What each try asked for: [department id, may widen] (inbound-audio slice 7).
         *
         * @var array<int, array{int|null, bool}>
         */
        public array $asked = [];

        /** Public and mutable so a waiting-room test can free an agent up mid-test. */
        public function __construct(public ?int $agentId) {}

        public function reserveFreeAgent(int $tenantId, array $skipUserIds = [], ?int $departmentId = null, bool $mayWiden = false): ?int
        {
            $this->reserved[] = $tenantId;
            $this->skipped[] = $skipUserIds;
            $this->asked[] = [$departmentId, $mayWiden];

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
 * Bind a stub AgentDirectory (SEC-1 slice 4) that hands back one dial endpoint for
 * any agent, the way `config('telephony.agent.endpoint')` used to before the lookup
 * moved to `users.sip_extension`. The `fakeNumberDirectory` shape, for the same
 * reason: it lets the flow + console tests stay about call MECHANICS while the real
 * database-backed lookup is proven in AgentDirectoryTest.
 *
 * 🔴 It encodes an invariant the code now guarantees rather than papering over one.
 * `AgentRouter` no longer offers an agent who holds no extension, so a reserved
 * agent always has an endpoint — which is what these tests assume. That guard is
 * proven for real in AgentRouterTest; the console's own "I have no phone" refusal is
 * proven in AgentConsoleNoPhoneTest, both against the real directory.
 *
 * `browserIdentityFor` is deliberately NOT stubbed: it reads the database already and
 * an agent with no extension never touches Asterisk's tables, so a rendered console
 * in a test simply shows the refusal.
 */
function fakeAgentDirectory(string $endpoint = 'PJSIP/1003'): AgentDirectory
{
    $directory = new class($endpoint) extends AgentDirectory
    {
        public function __construct(private readonly string $endpoint)
        {
            parent::__construct(app(AgentPhoneWriter::class));
        }

        public function endpointFor(int $userId): ?string
        {
            return $this->endpoint;
        }
    };

    app()->instance(AgentDirectory::class, $directory);

    return $directory;
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
    // F20: a ringing desk reads as not picked up unless a test says otherwise.
    $telephony->shouldReceive('isAnswered')->andReturnFalse()->byDefault();

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

/**
 * Build Asterisk's three phone tables in the test database (SEC-1, slice 2).
 *
 * On the real box these tables are created and owned by Asterisk's own Alembic
 * migration set, in the `asterisk` schema, and our migrations must never touch them
 * — two migration systems on one schema is how they drift. So the tests build their
 * own copy instead, carrying ONLY the columns App\Telephony\AgentPhoneWriter writes.
 *
 * Column types and widths were read off the staging box on 2026-09-07 and match it,
 * including the two custom value lists — so a value Asterisk would reject fails here
 * too, rather than passing locally and failing on the switch. The full read-back is
 * in `PRD/pre-work/server-build-log.md` §5e. One field was not read: `ps_endpoints.id`
 * is assumed to match its siblings at 255, and our longest id is eight characters.
 *
 * Postgres DDL is transactional, so RefreshDatabase rolls all of this back.
 */
function createAsteriskPhoneTables(): void
{
    DB::statement('drop schema if exists asterisk cascade');
    DB::statement('create schema asterisk');

    DB::statement("create type asterisk.ast_bool_values as enum
        ('0', '1', 'off', 'on', 'false', 'true', 'no', 'yes')");

    DB::statement("create type asterisk.pjsip_auth_type_values_v2 as enum
        ('md5', 'userpass', 'google_oauth')");

    DB::statement('create table asterisk.ps_endpoints (
        id varchar(255) primary key,
        context varchar(40),
        disallow varchar(200),
        allow varchar(200),
        auth varchar(255),
        aors varchar(2048),
        webrtc asterisk.ast_bool_values
    )');

    DB::statement('create table asterisk.ps_auths (
        id varchar(255) primary key,
        auth_type asterisk.pjsip_auth_type_values_v2,
        username varchar(40),
        password varchar(80)
    )');

    // 🔴 `qualify_frequency` is the one column here NOT read off the box — it was added at
    // S154, when AgentPhoneWriter started writing it (F29), and the laravel-boost MCP has
    // been down since S152 so the schema could not be re-read. `integer` is what Asterisk's
    // own Alembic set uses for it, a plain count of seconds. Confirm it against staging on
    // the next trip there; everything else below was read on 2026-09-07.
    DB::statement('create table asterisk.ps_aors (
        id varchar(255) primary key,
        max_contacts integer,
        remove_existing asterisk.ast_bool_values,
        qualify_frequency integer
    )');
}

/**
 * Build Asterisk's two music-on-hold tables in the test database (inbound-audio
 * slice 3). Same doctrine as createAsteriskPhoneTables() above — our migrations must
 * never touch the `asterisk` schema, so the tests carry their own copy.
 *
 * Shapes, widths and BOTH value lists were read off the staging box on 2026-09-21
 * (`\d asterisk.musiconhold`, `\d asterisk.musiconhold_entry`, `\dT+ asterisk.*`),
 * so a value Asterisk would reject fails here rather than on the switch.
 *
 * 🔴 TWO TRAPS, both real on the box:
 *  - the entry table carries a FOREIGN KEY to the class table, so the class must be
 *    written first. HoldMusicWriter's ordering is not decoration.
 *  - `loop_last` uses `asterisk.yesno_values`. There is a SEPARATE
 *    `asterisk.yes_no_values` in the same schema with identical members; spelling it
 *    the other way would build a copy that does not match the box.
 *
 * Safe to call with or without createAsteriskPhoneTables(), in either order.
 */
function createAsteriskMusicTables(): void
{
    DB::statement('create schema if not exists asterisk');

    DB::statement('drop table if exists asterisk.musiconhold_entry');
    DB::statement('drop table if exists asterisk.musiconhold');
    DB::statement('drop type if exists asterisk.moh_mode_values');
    DB::statement('drop type if exists asterisk.yesno_values');

    DB::statement("create type asterisk.moh_mode_values as enum
        ('custom', 'files', 'mp3nb', 'quietmp3nb', 'quietmp3', 'playlist')");

    DB::statement("create type asterisk.yesno_values as enum ('yes', 'no')");

    DB::statement('create table asterisk.musiconhold (
        name varchar(80) primary key,
        mode asterisk.moh_mode_values,
        directory varchar(255),
        application varchar(255),
        digit varchar(1),
        sort varchar(10),
        format varchar(10),
        stamp timestamp without time zone,
        loop_last asterisk.yesno_values
    )');

    DB::statement('create table asterisk.musiconhold_entry (
        name varchar(80) not null references asterisk.musiconhold(name),
        position integer not null,
        entry varchar(1024) not null,
        primary key (name, position)
    )');
}

/**
 * A sound finished playing (inbound-audio slice 4).
 *
 * 🔴 THE LINE IS NOT WHERE EVERY OTHER EVENT PUTS IT. This event carries no top-level
 * `channel`; the line is inside the playback object as `target_uri` = `channel:<id>`,
 * which is exactly why the switchboard dropped it until slice 4 gave it its own case.
 * Shaped from the 20 branch's own API spec (rest-api/api-docs/playbacks.json), not from
 * a note. `state` is `done` for a play that ran out AND for one we stopped (S165).
 *
 * @return array<string, mixed>
 */
/**
 * A caller pressed a key (inbound-audio slice 6, R2). Sent when the key is RELEASED, and
 * it carries a top-level channel — which is why the switchboard's default branch routes
 * it without a case of its own.
 *
 * @return array<string, mixed>
 */
function dtmfReceived(string $digit, string $legId): array
{
    return [
        'type' => 'ChannelDtmfReceived',
        'digit' => $digit,
        'duration_ms' => 100,
        'channel' => ['id' => $legId],
    ];
}

function playbackFinished(string $playbackId, string $legId, string $state = 'done'): array
{
    return [
        'type' => 'PlaybackFinished',
        'playback' => [
            'id' => $playbackId,
            'media_uri' => 'https://hcmis.test/closed-message/1/'.str_repeat('a', 64).'.wav',
            'target_uri' => 'channel:'.$legId,
            'state' => $state,
        ],
    ];
}
