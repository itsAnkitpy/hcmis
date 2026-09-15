<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\SetAgentPresence;
use App\Audit\Audit;
use App\Enums\PresenceStatus;
use App\Enums\RoleName;
use App\Models\AgentPresence;
use App\Models\AgentStatusHistory;
use App\Models\Call;
use App\Models\Tenant;
use App\Reporting\CallReportFilters;
use App\Reporting\CallReportService;
use App\Telephony\AgentDirectory;
use App\Telephony\LiveCallCounts;
use App\Telephony\TelephonyProvider;
use App\Tenancy\TenantContext;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * Live Agents (LB-1…LB-4) — the team leader's floor board: one row per agent who is
 * on shift right now (DialShree's "Real Time Report"). Everything here is arranged
 * from pieces that already exist — the live status board (AgentPresence, which already
 * reads a quiet screen as Offline), the open break stint (category + limit + overstay,
 * the BK-5 detail), and the per-agent call count (the same counting layer the reports
 * use, so a row can never disagree with the Agent Productivity report).
 *
 * LB-1: only currently-active agents appear — Ready / On a call / Wrapping up / On
 * break. Offline (logged out, or heartbeat gone quiet via effectiveStatus) drops off,
 * so the board stays "who's here now", not a directory. That same staleness rule keeps
 * this coherent with the dashboard counts: a crashed tab left mid-break reads Offline
 * here too, never a phantom "on break".
 *
 * LB-3: the columns are the ones that need NO phone line — name, status, time-in-status,
 * break detail, calls today. The live call timer, waiting-queue, hopper and listen-in
 * columns wait for the trunk (see the spec's "waits for the phone line").
 *
 * Gate (LB): Call's viewAny — the same audience as the dashboard and reports (global
 * staff / team leader / QC). The tenant wall scopes a per-client leader to their own
 * agents for free.
 */
class LiveAgents extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Live Agents';

    protected static ?string $title = 'Live Agents';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.live-agents';

    /**
     * How far back the Stuck tab looks (LB-6) — roughly one shift.
     */
    private const STUCK_WINDOW_HOURS = 12;

    /**
     * Which tab is open — 'floor' (everyone), 'stuck', or a PresenceStatus value. Page
     * state on purpose: the board re-asks the server every 15s and rebuilds every row,
     * so a choice held anywhere else would be wiped on the next tick (LB-9).
     */
    public string $tab = 'floor';

    /**
     * Which heading was clicked, and which way (LB-11). Null means the board is in its
     * default urgency order — sorting is something you do on purpose, so it must never
     * be what you get when the page opens. Page state for the same reason as $tab: the
     * 15-second poll rebuilds every row, and a sort held anywhere else would undo itself
     * on the next tick.
     */
    public ?string $sort = null;

    public string $sortDirection = 'asc';

    /**
     * The headings a viewer may sort by (LB-11). A hard list because a public Livewire
     * property is settable from the browser — anything not on it is ignored rather than
     * read blindly off a row.
     *
     * Agent finds a person in a long list; For ranks the whole floor by who has been
     * sitting longest; Calls today is the one question the screen cannot otherwise
     * answer; Client (global staff only, LB-13) groups the list by client without
     * building grouping. Status is not here (sorting by it returns the order the page
     * already opens in) and neither is Break (the column is mostly dashes).
     */
    private const SORTABLE = ['name', 'inStatusMinutes', 'callsToday', 'client'];

    public static function canAccess(): bool
    {
        return Gate::allows('viewAny', Call::class);
    }

    public function getSubheading(): ?string
    {
        return 'Everyone on the floor right now — refreshes every 15 seconds.';
    }

    /**
     * The supervisor's own browser phone (SM-2): the same per-user identity the agent
     * console registers with, resolved for whoever is reading the board.
     *
     * 🔴 This registers a phone. It does NOT join the routing pool. Membership is
     * decided by a board row (AgentRouter::reserveFreeAgent reads status + freshness
     * + extension), and nothing on this page writes one — the row is created only by
     * SetAgentPresence, which this page calls in exactly one place, to force somebody
     * else Offline. So a supervisor may hold a registered phone all day and never be
     * handed a customer (SM-3, pinned by SupervisorPhoneTest).
     *
     * Self-gating for anyone who should not have a phone: the directory returns nulls
     * for a user with no number or no key (PP-12), and the panel then says so rather
     * than registering as somebody else. A QC who only reviews recordings is never
     * issued one, so they never register.
     *
     * @return array{extension: ?string, password: ?string, wsUrl: ?string, sipDomain: string}
     */
    public function getPhoneConfig(): array
    {
        return app(AgentDirectory::class)->browserIdentityFor((int) auth()->id());
    }

    /**
     * Does the reader hold a phone of their own (SM slice 2)? The Listen button is drawn
     * only for someone who does — slice 1's panel already hides itself for anyone else,
     * but a button on a ROW would still have been clickable and would have silently done
     * nothing, which reads as a broken screen rather than as an absent capability.
     */
    public function hasPhone(): bool
    {
        return $this->getPhoneConfig()['extension'] !== null;
    }

    /**
     * One row per currently-active agent (LB-1), most-actionable first: on a call,
     * then wrapping up, then ready, then on break — and within a state the longest
     * time-in-status first, so a stretched call or an overstayed break surfaces.
     *
     * Reads three already-built sources: the board rows (kept when effectiveStatus is
     * not Offline), the open status stint per agent (time-in-status + break detail),
     * and today's per-agent call count from the counting layer. All tenant-walled by
     * the request context.
     *
     * @return array<int, array{id: int|null, name: string, status: PresenceStatus, statusLabel: string, statusColor: string, inStatusMinutes: int, startedAtMs: int|null, breakCategory: string|null, limitMinutes: int|null, overstayed: bool, callsToday: int, client: string|null, canLogOut: bool, canListen: bool}>
     */
    public function roster(): array
    {
        $presences = AgentPresence::query()
            // The client name is an eager-load, not a new join path — every board row is
            // already client-owned and BelongsToTenant hands it the tenant() relation.
            // Loaded only for the staff who can see the column (LB-13).
            ->with($this->showsClient() ? ['user', 'tenant'] : ['user'])
            ->get()
            ->filter(fn (AgentPresence $presence): bool => $presence->effectiveStatus() !== PresenceStatus::Offline);

        if ($presences->isEmpty()) {
            return [];
        }

        $stints = AgentStatusHistory::query()
            ->open()
            ->whereIn('user_id', $presences->pluck('user_id'))
            ->with('breakCategory')
            ->get()
            ->keyBy('user_id');

        $callsToday = collect(app(CallReportService::class)->agentProductivity($this->todayFilters()))
            ->keyBy('agent_id');

        $rows = $presences->map(function (AgentPresence $presence) use ($stints, $callsToday): array {
            $status = $presence->effectiveStatus();
            $stint = $stints->get($presence->user_id);
            $onBreak = $status === PresenceStatus::OnBreak;
            $limit = $onBreak ? $stint?->limit_minutes : null;

            return [
                'id' => $presence->user_id,
                'name' => $presence->user?->name ?? '—',
                'status' => $status,
                'statusLabel' => $status->label(),
                'statusColor' => $this->statusColor($status),
                'inStatusMinutes' => $stint ? (int) $stint->started_at->diffInMinutes(now()) : 0,
                // When this status began, as a plain instant — the browser counts up from
                // it once a second (LB-4). Sent as an absolute moment rather than a
                // duration so a tab that Chrome throttles in the background shows the
                // right number the instant it is looked at again, instead of resuming a
                // counter that stopped. Null when there is no open stint to count from.
                'startedAtMs' => $stint?->started_at->getTimestampMs(),
                'breakCategory' => $onBreak ? ($stint?->breakCategory?->label ?? 'Break') : null,
                'limitMinutes' => $limit,
                'overstayed' => $limit !== null && now()->greaterThan($stint->started_at->copy()->addMinutes($limit)),
                'callsToday' => (int) ($callsToday->get($presence->user_id)['total'] ?? 0),
                'client' => $this->showsClient() ? ($presence->tenant?->name ?? '—') : null,
                // LB-8: never on a row that is mid-call. See stuckRoster() for why.
                'canLogOut' => $status !== PresenceStatus::OnCall,
                // SM slice 2, the mirror image: listening is only offered on a row that IS
                // mid-call, because there is nothing to listen to otherwise.
                'canListen' => $status === PresenceStatus::OnCall,
            ];
        })->all();

        usort($rows, fn (array $a, array $b): int => [$this->statusOrder($a['status']), -$a['inStatusMinutes']]
            <=> [$this->statusOrder($b['status']), -$b['inStatusMinutes']]);

        return $rows;
    }

    /**
     * The Stuck tab (LB-6) — agents whose screen stopped punching its 15-second time
     * clock while their stored status still says they are working. A laptop lid shut at
     * lunch, a crashed tab, a dropped wifi: the person is not there but the board still
     * says Ready, or On a call, or On break.
     *
     * Deliberately a SEPARATE, SMALLER read than roster() — a name, what they were last
     * doing, and how long ago they last punched. No break detail, no calls-today. roster()
     * keeps meaning exactly what it means today (LB-1), so nothing that feeds the head
     * count or the dashboard tiles can move.
     *
     * Two things must both be true: the stored status is not Offline (they never said
     * they were leaving — someone who logged out at 6pm also stopped punching, and they
     * went home), and the last punch is older than the stale window. Someone who never
     * punched at all (last_seen_at empty) is left off: there is no "last responded" to
     * show and no timestamp to measure the 12 hours against. NULL fails both comparisons
     * below, so SQL drops them for free.
     *
     * The 12-hour floor keeps the tab to roughly one shift. Without it the tab fills with
     * everyone who ever forgot to log out, going back months, and the number beside it
     * stops meaning anything. Not "since midnight": a night-shift agent whose laptop dies
     * at 11pm must not vanish off the tab at midnight while a manager is still watching.
     *
     * Free ride: agent_presence already carries an index on (tenant_id, status,
     * last_seen_at) — the exact three things this filters on.
     *
     * @return array<int, array{id: int|null, name: string, statusLabel: string, quietForMinutes: int, canLogOut: bool}>
     */
    public function stuckRoster(): array
    {
        $staleBefore = now()->subSeconds((int) config('telephony.presence.stale_after_seconds'));

        return AgentPresence::query()
            ->with('user')
            ->where('status', '!=', PresenceStatus::Offline)
            ->where('last_seen_at', '<', $staleBefore)
            ->where('last_seen_at', '>=', now()->subHours(self::STUCK_WINDOW_HOURS))
            ->orderBy('last_seen_at') // longest-quiet first, the same urgency order the board uses
            ->get()
            ->map(fn (AgentPresence $presence): array => [
                'id' => $presence->user_id,
                'name' => $presence->user?->name ?? '—',
                'statusLabel' => $presence->status->label(),
                'quietForMinutes' => (int) $presence->last_seen_at->diffInMinutes(now()),
                // LB-8: no button at all on a row the board still shows mid-call. This is
                // the exact harm VICIdial's users hit — a manager reads "stuck", presses
                // log out, and cuts off an agent who was talking to a customer on a slow
                // connection. A frozen "On a call" gets sorted out by walking over.
                'canLogOut' => $presence->status !== PresenceStatus::OnCall,
            ])
            ->all();
    }

    /**
     * The tab strip (LB-9), counted off the two lists the page already built — no extra
     * query. Our words, not DialShree's, so a tab never disagrees with the badge on the
     * row beneath it. The first tab is the head count: everyone currently working, which
     * excludes the stuck (LB-6 — a shut laptop is not on the floor).
     *
     * Empty states keep their tab (showing "0") rather than disappearing — a strip that
     * reshuffles itself every 15 seconds is harder to click than one that sits still.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array{key: string, label: string, count: int}>
     */
    public function tabs(array $rows, int $stuckCount): array
    {
        $tabs = [['key' => 'floor', 'label' => 'On the floor', 'count' => count($rows)]];

        foreach ([PresenceStatus::OnCall, PresenceStatus::WrappingUp, PresenceStatus::Ready, PresenceStatus::OnBreak] as $status) {
            $tabs[] = [
                'key' => $status->value,
                'label' => $status->label(),
                'count' => count(array_filter($rows, fn (array $row): bool => $row['status'] === $status)),
            ];
        }

        $tabs[] = ['key' => 'stuck', 'label' => 'Stuck', 'count' => $stuckCount];

        return $tabs;
    }

    /**
     * The three live call numbers (Call Stats CS-5): how many calls are connected right
     * now, how many are ringing, and how many callers are holding. Read out of the
     * pigeonhole the listener leaves them in — nothing is counted here, and no network
     * call is made to the phone engine on a page load.
     *
     * Null means the phone service is not reporting (CS-7), which the board must SAY
     * rather than draw as three zeros: a calm floor and a dead listener look identical
     * otherwise, and only one of them needs somebody to do something about it.
     *
     * Who is counted follows the same line as the Client column (LB-13): a team leader
     * sees their own client, our own global staff see every client added together. Notes
     * are asked for by name because a cache cannot be asked for "everything with this
     * prefix" — so the client list is read here, from our own database.
     *
     * No filtering by client status: a suspended client has no live calls, so it simply
     * has no note.
     *
     * A fourth value comes back alongside the three (Longest Wait LW-2): the MOMENT the
     * caller who has been holding longest arrived, or null when nobody is holding. The
     * screen turns that into "how long ago" against its own clock — the note carries an
     * arrival time precisely so it can be up to twenty seconds old without the wait it
     * describes being twenty seconds wrong.
     *
     * @return array{active: int, ringing: int, waiting: int, oldestWaitingAt: int|null}|null
     */
    public function callStats(): ?array
    {
        $counts = app(LiveCallCounts::class);

        if (! $counts->isReporting()) {
            return null;
        }

        $tenantId = TenantContext::id();

        return $counts->read($this->showsClient()
            ? array_map('intval', Tenant::query()->pluck('id')->all())
            : ($tenantId === null ? [] : [$tenantId]));
    }

    /**
     * Click a heading to sort by it; click the same one again to flip the direction
     * (LB-11).
     */
    public function sortBy(string $column): void
    {
        if (! in_array($column, self::SORTABLE, true)) {
            return;
        }

        $this->sortDirection = $this->sort === $column && $this->sortDirection === 'asc' ? 'desc' : 'asc';
        $this->sort = $column;
    }

    /**
     * The rows the open tab shows — a filter (and, if a heading was clicked, a re-sort)
     * over the roster that was already fetched, never a second read.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public function visibleRows(array $rows): array
    {
        if ($this->tab !== 'floor') {
            $rows = array_values(array_filter($rows, fn (array $row): bool => $row['status']->value === $this->tab));
        }

        if (! in_array($this->sort, self::SORTABLE, true)) {
            return $rows;
        }

        // Names and client names compare case-blind, so "asha" cannot land after "Ravi"
        // the way a raw byte comparison would put it. Numbers fall through unchanged.
        $value = function (array $row): mixed {
            $value = $row[$this->sort];

            return is_string($value) ? mb_strtolower($value) : $value;
        };

        usort($rows, fn (array $a, array $b): int => $this->sortDirection === 'asc'
            ? $value($a) <=> $value($b)
            : $value($b) <=> $value($a));

        return $rows;
    }

    /**
     * Whether the Client column shows (LB-13) — only for our own global staff, who are
     * the only people who ever see more than one client's agents in one list. A team
     * leader inside a client never sees it: the tenant wall means every row is theirs,
     * so a column repeating their own client's name on every line says nothing.
     */
    public function showsClient(): bool
    {
        return auth()->user()?->operatesGlobally() ?? false;
    }

    /**
     * Who may end somebody else's session (LB-12): team leaders and our own global
     * staff, and nobody else. QC, trainers and client-side users still see the Stuck
     * tab — there is simply no button on their version of it.
     *
     * A QC person's job is listening to recordings and grading them; a client-side user
     * is the customer looking at their own numbers and must never be able to knock one
     * of their own agents offline. Genesys splits watching the floor from controlling it
     * the same way, deliberately.
     */
    public function canForceLogOut(): bool
    {
        $user = auth()->user();

        return $user !== null
            && ($user->operatesGlobally() || $user->hasRole(RoleName::TeamLeader->value));
    }

    /**
     * Force a stuck agent offline (LB-8, LB-12, LB-15). Three things happen, all inside
     * the target agent's own client context: the board row goes Offline, the open diary
     * entry is closed THROUGH THE DOOR (never around it — an entry left open forever is
     * how break and availability numbers get quietly corrupted), and an audit note
     * records who did it to whom.
     *
     * The button sits on BOTH lists, and the two cases end the diary entry at different
     * moments — deliberately:
     *
     *  - a STUCK agent's screen really had died, so the entry closes at "last punch plus
     *    the stale window" and reads `stale`. Priya stopped working at 2:15pm, not at
     *    3:47pm when somebody noticed;
     *  - an agent still ON THE FLOOR is answering fine and has plainly gone home leaving
     *    the machine on — the one case the Stuck tab can never catch, and the one that
     *    quietly rings real customers at a dead desk. The entry closes now and reads
     *    `forced`, which is the honest reason.
     *
     * RecordStatusStint decides which, off the pre-write heartbeat. Nothing here has to
     * know the difference.
     *
     * Every guard is re-checked here, not just in the view: the buttons are only drawn
     * for permitted viewers on permitted rows, but the method itself is reachable from
     * any browser. A row that has since gone Offline, or that is now on a call, is left
     * alone and the supervisor is told why.
     */
    public function forceLogOut(int $userId): void
    {
        abort_unless($this->canForceLogOut(), 403);

        $presence = AgentPresence::query()->where('user_id', $userId)->first();

        if ($presence === null
            || $presence->status === PresenceStatus::Offline
            || $presence->status === PresenceStatus::OnCall) {
            Notification::make()
                ->title('Nothing to log out')
                ->body('That agent is already offline, or has picked up a call since this page last refreshed.')
                ->warning()
                ->send();

            return;
        }

        $name = $presence->user?->name ?? 'The agent';

        // Pin to the agent's own client for the write: a team leader is already sitting
        // in it, but our global staff sit in none, and the diary row would have no client
        // to be stamped with.
        TenantContext::run((int) $presence->tenant_id, function () use ($presence): void {
            SetAgentPresence::run((int) $presence->user_id, PresenceStatus::Offline, forced: true);
            Audit::agentForcedOffline($presence);
        });

        Notification::make()
            ->title("{$name} was logged out")
            ->success()
            ->send();
    }

    /**
     * Listen in on an agent's live call (SM slice 2). Silent: the agent and the customer
     * hear nothing, and the call is not touched in any way.
     *
     * Three things happen. The agent's board row is read, which is what proves they are
     * on a call AND which client they belong to (the row is client-owned; the tenant wall
     * means a team leader simply cannot find somebody else's agent here). The access is
     * written to the audit log inside that client, for the same reason every recording
     * playback is (SM-5). Then the same kind of control signal Transfer and Conference
     * already send goes to the running phone program, which finds the live call and rings
     * this supervisor's own phone.
     *
     * 🔴 WHO IS LISTENING COMES FROM THE SERVER, never from the browser. The button sends
     * only which agent to listen to; the supervisor is whoever is logged in.
     *
     * Every guard is re-checked here rather than trusted from the view, exactly as
     * forceLogOut does: the buttons are drawn only for permitted readers on permitted
     * rows, and the method is still reachable from any browser.
     *
     * 🔴 SQ-4 settled for this slice: the gate is the board's own (team leader / QC /
     * global staff), not a new permission. Listening is invisible to the customer and
     * this page is already walled to exactly the people who supervise a floor. Barge is
     * the one that puts a third voice on a customer's call, and splitting the permission
     * belongs with it in slice 4.
     */
    public function listenTo(int $agentUserId): void
    {
        abort_unless(static::canAccess(), 403);

        if (! $this->hasPhone()) {
            Notification::make()
                ->title('You have no phone to listen on')
                ->body('Ask an administrator to issue you one from your user record.')
                ->warning()
                ->send();

            return;
        }

        $presence = AgentPresence::query()->where('user_id', $agentUserId)->first();

        if ($presence === null || $presence->status !== PresenceStatus::OnCall) {
            Notification::make()
                ->title('Nothing to listen to')
                ->body('That agent is not on a call any more, or their call ended since this page last refreshed.')
                ->warning()
                ->send();

            return;
        }

        $name = $presence->user?->name ?? 'The agent';

        // Pinned to the agent's own client for the write, the same reason forceLogOut
        // pins it: a team leader is already sitting in it, our global staff sit in none,
        // and an audit row with no client is a row nobody inside that client can read.
        TenantContext::run((int) $presence->tenant_id, fn () => Audit::monitoringStarted($presence));

        app(TelephonyProvider::class)->signal('listen', [
            'agentUserId' => (string) $agentUserId,
            'supervisorUserId' => (string) auth()->id(),
        ]);

        Notification::make()
            ->title("Listening in on {$name}")
            ->body('Your phone will pick up by itself. Press Stop on your phone panel when you are done.')
            ->success()
            ->send();
    }

    /**
     * All agents, today (midnight → now) — the filter the calls-today column reads
     * through. Built directly: the board has no filter form by design (LB).
     *
     * 🔴 MIDNIGHT ON THE CLIENT'S CLOCK (S118, CE-10a). On UTC midnight the board's
     * calls-today column reset five and a half hours into the Indian working day.
     */
    private function todayFilters(): CallReportFilters
    {
        return new CallReportFilters(from: CallReportFilters::clientToday(), to: now());
    }

    /**
     * The sort priority within the board — most-actionable state first.
     */
    private function statusOrder(PresenceStatus $status): int
    {
        return match ($status) {
            PresenceStatus::OnCall => 0,
            PresenceStatus::WrappingUp => 1,
            PresenceStatus::Ready => 2,
            PresenceStatus::OnBreak => 3,
            PresenceStatus::Offline => 4, // never shown (LB-1) — kept exhaustive
        };
    }

    /**
     * The status pill colour — the same mapping the live availability counts use, so
     * a state reads the same colour on both screens.
     */
    private function statusColor(PresenceStatus $status): string
    {
        return match ($status) {
            PresenceStatus::Ready => 'success',
            PresenceStatus::OnCall => 'info',
            PresenceStatus::WrappingUp => 'warning',
            PresenceStatus::OnBreak => 'gray',
            PresenceStatus::Offline => 'danger',
        };
    }
}
