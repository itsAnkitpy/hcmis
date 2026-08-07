<?php

declare(strict_types=1);

namespace App\Telephony\Flows;

use App\Enums\CallDirection;
use App\Enums\CallOutcome;
use App\Models\Call;
use App\Models\CallHandoff;
use App\Models\Tenant;
use App\Telephony\AgentDirectory;
use App\Telephony\AgentRouter;
use App\Telephony\AriConnectionLost;
use App\Telephony\NumberDirectory;
use App\Telephony\RecordingSession;
use App\Telephony\TelephonyException;
use App\Telephony\TelephonyProvider;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The one call-to-agent flow B4 v1 draws properly, generalised to both
 * directions (B-outbound D4). It replaces the scripted demo that used to live
 * inside TelephonyListen — no fixed timings, no sleep/wait/drain. It reacts to
 * raw engine events as the listener hands them over and keeps a little state
 * about the single call in flight.
 *
 * Inbound (an outside caller reaches a browser agent — CP2a):
 *   caller arrives  -> answer the caller, ring the agent's extension
 *   agent answers   -> join the two legs + record both sides (D4 snoops)
 *   either hangs up -> stop recording, drop the survivor, fold the conversation
 *   both files done -> queue the stereo merge
 *
 * Outbound (the agent clicks Dial — B-outbound M2, agent-first ordering):
 *   the web AgentConsole originates the AGENT leg first, carrying the customer's
 *   number as the agent leg's tag detail (StasisStart.args[1], CP-O0). When that
 *   agent leg arrives here we dial the CUSTOMER leg; the customer answering joins
 *   and records exactly as inbound does. The outbound customer maps onto the
 *   internal callerLegId, so join/record/teardown reuse unchanged.
 *
 * No-answer needs no timer of ours (B4 nod c): placeCall carries Asterisk's own
 * originate timeout, so an unanswered leg simply ends and we see its
 * ChannelDestroyed while still ringing — that is the no-answer signal.
 *
 * One call per handler (B2.1): the switchboard makes a fresh handler for each new
 * call and routes each event to the right one, so many calls run side by side. This
 * handler is disposed at teardown — never reused — and registers/forgets the legs it
 * places, and hands its pending recording merge, through the switchboard (FD-1/2/4).
 *
 * Many agents on one call (B2.4b CD-2): the call is one caller leg + a SET of agent
 * legs (AgentLeg, each ringing or connected) sharing one voice mixer — generalised
 * from B2.4a's fixed "one caller + one agent (+ a transient transfer leg)" so cold
 * transfer, 3-way conference, and later supervisor-barge all just add or drop a member.
 *
 * Nobody is hung up on for our staffing (B2.3b-i): an inbound caller used to be cut
 * off on TWO paths — nobody free when they arrived, and the agent we rang letting
 * their phone ring out. Both now put the caller in the waiting room instead (state
 * Waiting, hold music on their own line), where the listener's five-second heartbeat
 * retries them (tryAgain) until a desk frees up, they give up, or the client's maximum
 * hold time runs out. A caller who never reaches an agent leaves a `calls` row written
 * from here — the missed-call list — because no agent's screen exists to write one.
 */
class CallToAgentFlow
{
    private CallFlowState $state = CallFlowState::Idle;

    private ?string $callerLegId = null;

    private ?string $conversationId = null;

    /**
     * This call's agent legs (B2.4b CD-2), keyed by leg id — one connected agent in a
     * plain 1:1 call, plus a *ringing* one while a transfer/conference is bringing a
     * second agent in, plus a second *connected* one during a live 3-way. Replaces
     * B2.4a's `agentLegId` + `transferLegId` + `servingAgentId` scalars: the serving
     * agent is now "any connected member" (isServingAgent), and teardown follows the
     * uniform participant-set rule (onLegEnded) instead of hard-coded 2-leg branches.
     *
     * @var array<string, AgentLeg>
     */
    private array $agents = [];

    /**
     * Why a second agent is currently being rung in (B2.4b CD-5): transfer (drop the
     * existing agent when B answers) vs conference (keep them — the 3-way). Set when the
     * ring starts (beginAddedAgent), read once on B's answer, then cleared. Null whenever
     * no added-agent ring is in flight. The ring itself is identical for both intents.
     */
    private ?AddedAgentIntent $addedAgentIntent = null;

    /**
     * The call's tracking number (B3 D3): the UUID the web console minted at dial
     * and rode in as the agent leg's third arg. On OUTBOUND it is the recording's
     * callId so RecordingReady carries our UUID (= the calls row's correlation_id),
     * letting the queued listener attach the recording by UUID. Stays null on inbound
     * (the web injects no UUID — the customer originates). Inbound now attaches via
     * the TICKET instead (B2.4b TH-4): connectAgent names the recording ticket-first,
     * so an inbound recording carries the same ticket the screen stamped on the row.
     */
    private ?string $correlationId = null;

    /**
     * The call's ticket number (the per-call id, FD-3): a unique id stamped on every
     * call so many interleaved calls are followable in the log, and the handle the
     * listener->browser handoff points at (B2.4b TH-2). Outbound REUSES the web's
     * tracking number (the UUID above); inbound MINTS a fresh one. It IS the
     * recording's callId now (TH-4): connectAgent names the recording ticket-first
     * and beginCall hands this same ticket to the agent's screen (via call_handoffs),
     * so an inbound recording attaches by matching ids exactly as outbound does.
     */
    private ?string $ticketNumber = null;

    private ?RecordingSession $recording = null;

    /**
     * The inbound call's own details, kept for as long as the call lives (B2.3b-i).
     * beginCall() used to read all of these, use them once and drop them; a caller who
     * ends up in the waiting room needs them much later — to ring the NEXT agent with
     * the caller's number on it, and to write the missed-call record for a caller no
     * agent ever reached. All stay null on an outbound call, which is what makes
     * recordMissedCall() a no-op there: the agent's screen owns that row.
     */
    private ?int $tenantId = null;

    private ?string $callerNumber = null;

    private ?string $dialledNumber = null;

    /** When the caller arrived — the missed-call record's start, and the hold clock's zero. */
    private ?Carbon $startedAt = null;

    /**
     * This client's own waiting-room settings, read once when the call arrives (QD-7):
     * how long ONE agent's phone rings, and how long this caller may hold in total
     * before we stop waiting. Null when the client has set neither (or, in the flow
     * tests, when no client row exists) — the config fallback applies at each use.
     */
    private ?int $ringSeconds = null;

    private ?int $maxHoldSeconds = null;

    /**
     * The agents already rung for THIS caller (QD-4's per-call skip list). Without it a
     * waiting caller cycles between hold music and the same silent desk forever: the
     * agent's phone rings out, the board still says Ready, and the next sweep picks
     * them again. Deliberately per-call — taking a non-answering agent off the board
     * for everyone needs a sixth presence state and a screen to clear it (B2.3b-ii).
     *
     * @var array<int, int>
     */
    private array $rungAgentIds = [];

    private readonly AgentRouter $router;

    private readonly AgentDirectory $directory;

    private readonly NumberDirectory $numbers;

    public function __construct(
        private readonly TelephonyProvider $telephony,
        private readonly HandlerRegistry $registry,
        ?AgentRouter $router = null,
        ?AgentDirectory $directory = null,
        ?NumberDirectory $numbers = null,
    ) {
        $this->router = $router ?? app(AgentRouter::class);
        $this->directory = $directory ?? app(AgentDirectory::class);
        $this->numbers = $numbers ?? app(NumberDirectory::class);
    }

    /** This call's ticket number (FD-3): the log tag, and later the durable waiting-line handle. */
    public function ticketNumber(): ?string
    {
        return $this->ticketNumber;
    }

    /**
     * Feed the flow one raw engine event. It never throws on a refused verb — a
     * mid-call telephony error aborts that one call and the flow resets, so the
     * listener keeps running. A lost pipe (AriConnectionLost) is the listener's
     * problem and is re-thrown untouched.
     *
     * 🔴 A LEG ENDING ARRIVES UNDER TWO NAMES, and for a long time we only listened for
     * one (found live on staging, S87). "The call was destroyed" (ChannelDestroyed) is
     * what the engine sends while the leg is still ours; "the call left us" (StasisEnd)
     * is what it sends when the leg goes away on its own — which is what happens every
     * time an outside caller hangs up. **Only the second one arrives in that case**, and
     * with just the first handled the app never learned the caller was gone: it held them
     * in the waiting room until the maximum-hold timer fired and then recorded them as
     * "we stopped waiting" instead of "they gave up".
     *
     * It went unnoticed until the waiting room existed because until then the app was
     * always the one hanging up, and a leg we hang up IS destroyed while still ours.
     *
     * Treating the two as the same thing is safe HERE, and the reason is worth writing
     * down: a leg would also leave us without ending if we sent it back out to the
     * dialplan (the provider's `transfer` verb), and **nothing in this app calls it** —
     * cold transfer does its work with bridge surgery and a hang-up instead. So a leg
     * leaving always means it is genuinely gone. Should `transfer` ever be used, this is
     * the line that has to learn the difference.
     *
     * Handling both is harmless when both arrive: whichever lands first tears its call
     * down, and the second finds no handler (or no such leg) and falls through.
     *
     * @param  array<string, mixed>  $event
     */
    public function handle(array $event): void
    {
        try {
            match ($event['type'] ?? '') {
                'StasisStart' => $this->onArrival($event),
                'ChannelDestroyed', 'StasisEnd' => $this->onLegEnded($event),
                default => null,
            };
        } catch (AriConnectionLost $exception) {
            throw $exception;
        } catch (TelephonyException $exception) {
            $this->abort($exception);
        }
    }

    /**
     * A leg entered our Stasis app. The kinds are told apart by the args we
     * tagged them with:
     *   ['snoop']            -> our recording taps; infrastructure, ignored.
     *   ['agent']            -> an agent leg WE placed (inbound pickup, or a
     *                           transfer/conference's added agent) answering.
     *   ['agent', <number>, <uuid?>, <agentId?>]
     *                        -> outbound entry: the agent leg, carrying the customer
     *                           number to dial next (agent-first, CP-O0), the call's
     *                           tracking number (the B3 UUID), and the serving agent's
     *                           user id (B2.4a, so an outbound call is transferable).
     *   ['outbound']         -> outbound customer pickup (the customer leg we placed).
     *   []                   -> an untagged outside caller (inbound entry).
     *
     * @param  array<string, mixed>  $event
     */
    private function onArrival(array $event): void
    {
        $legId = $event['channel']['id'] ?? '';
        $args = $event['args'] ?? null;

        if ($args === ['snoop']) {
            return;
        }

        if ($args === ['agent']) {
            // A ringing agent leg we placed answered. Which kind depends on where we are:
            // the inbound bootstrap pickup (RingingAgent) connects the call; an added
            // agent (AddingAgent) forks on intent — transfer drops A, conference keeps A.
            $agent = $this->agents[$legId] ?? null;

            if ($agent === null || $agent->connected) {
                return;
            }

            if ($this->state === CallFlowState::RingingAgent) {
                $this->connectAgent();
            } elseif ($this->state === CallFlowState::AddingAgent) {
                $this->onAddedAgentAnswered($legId);
            }

            return;
        }

        // Outbound entry (agent-first): the agent's own leg arrives first carrying the
        // customer's number (arg 1), the call's UUID (arg 2), and the serving agent's
        // user id (arg 3). >= 2 tolerates the pre-B3 two-arg shape, the three-arg one,
        // and the four-arg one — each trailing detail is optional, default null.
        if (is_array($args) && count($args) >= 2 && $args[0] === 'agent' && $this->state === CallFlowState::Idle) {
            $this->beginOutboundCall(
                $legId,
                (string) $args[1],
                isset($args[2]) ? (string) $args[2] : null,
                isset($args[3]) ? (int) $args[3] : null,
            );

            return;
        }

        if ($args === ['outbound']) {
            if ($this->state === CallFlowState::RingingCustomer && $legId === $this->callerLegId) {
                $this->connectAgent();
            }

            return;
        }

        if ($args === [] && $this->state === CallFlowState::Idle) {
            // The caller's number rides the arrival event (channel.caller.number,
            // the same field translate() reads). An anonymous caller presents an
            // empty string — treat that as "no number" so we present nothing.
            $callerNumber = $event['channel']['caller']['number'] ?? null;
            $this->beginCall($event, $legId, $callerNumber !== '' ? $callerNumber : null);
        }
    }

    /**
     * Pick a free agent among several and ring THEM (B2.2b — the headline). Read the
     * company label off the call (RD-1), reserve the first free agent on that company's
     * board the instant we ring them (RD-3/RD-4), and ring that specific agent's phone
     * (RD-2 resolver) — carrying the caller's own number as the agent leg's caller-ID so
     * the browser reads it off the ringing call to look up the lead (B4 D4).
     *
     * An unroutable number is still a clean end (ND-4): there is no client, so there is
     * no board to read and nobody to wait for — the caller is hung up, not routed to a
     * guess. But "nobody is free" is no longer an end at all (B2.3b-i QD-4, the first of
     * the two doors): the caller is answered and put in the WAITING ROOM with music on,
     * and the listener's heartbeat keeps trying (tryAgain) until a desk frees up.
     *
     * That is why the caller is answered BEFORE we know whether anyone is free — an
     * unanswered leg is not a call yet, and there is nothing to play music into.
     *
     * @param  array<string, mixed>  $event
     */
    private function beginCall(array $event, string $callerLegId, ?string $callerNumber = null): void
    {
        $this->callerLegId = $callerLegId;
        $this->ticketNumber = (string) Str::uuid();   // inbound mints a fresh ticket (FD-3)
        $this->callerNumber = $callerNumber;
        $this->dialledNumber = $this->dialledNumber($event);
        $this->startedAt = now();

        $tenantId = $this->companyLabel($event);

        // ND-4: the number is not in the list, or is switched off. Nothing to route to —
        // the agent board is never read, so this must not say "all busy". No missed-call
        // record either: a row has to belong to a client, and this call has none.
        if ($tenantId === null) {
            rescue(fn () => $this->telephony->hangup($callerLegId), report: false);
            Log::info('Inbound call: the dialled number belongs to no active client — ending the call (unknown number).', [
                'ticket' => $this->ticketNumber,
                'caller' => $callerLegId,
                'dialled' => $this->dialledNumber,
            ]);
            $this->dispose();

            return;
        }

        $this->tenantId = $tenantId;
        $this->loadQueueSettings($tenantId);

        $this->telephony->answer($callerLegId);

        $reservedAgentId = $this->reserveNextAgent();

        // QD-4 door one: the client is known and nobody is free. The caller waits.
        if ($reservedAgentId === null) {
            $this->enterWaitingRoom('nobody was free when the call arrived');

            return;
        }

        $this->ringAgent($reservedAgentId);
    }

    /**
     * This client's own ring / maximum-hold settings (QD-7), read once per call. Falls
     * back to the config defaults when the client has set none — and when there is no
     * client row at all, which is the flow tests' posture (they prove call mechanics
     * against a stub number-directory, with no `tenants` row behind the label).
     */
    private function loadQueueSettings(int $tenantId): void
    {
        $tenant = Tenant::query()->find($tenantId);

        $this->ringSeconds = $tenant?->ringSeconds();
        $this->maxHoldSeconds = $tenant?->maxHoldSeconds();
    }

    /**
     * Reserve the next agent to try for this caller (RD-3/RD-4), skipping everyone this
     * caller has already been rung out on (QD-4). Null means nobody is free right now —
     * which starts the wait on a new call, and simply continues it on a sweep.
     */
    private function reserveNextAgent(): ?int
    {
        $reservedAgentId = $this->router->reserveFreeAgent((int) $this->tenantId, $this->rungAgentIds);

        if ($reservedAgentId !== null) {
            $this->rungAgentIds[] = $reservedAgentId;
        }

        return $reservedAgentId;
    }

    /**
     * Ring one reserved agent for this caller: hand their screen the call's ticket, then
     * place the leg with the caller's own number on it as caller-ID (B4 D4) and the
     * client's ring duration as the timeout (QD-7) — the setting that has existed on
     * placeCall() since B1 and that nothing could reach until now.
     *
     * TH-5/TH-6 (the handoff): the ticket goes to the reserved agent's screen via the
     * drop-off table BEFORE the phone rings — the screen reads it at ring-time and stamps
     * it on the calls row so the inbound recording attaches by matching ids. The listener
     * runs with no logged-in user, so a context-less write is RLS default-denied; it
     * writes scoped to the call's own company (the AttachRecordingToCall run() precedent,
     * minus the discovery). Prune-then-insert wipes this agent's prior note first, capping
     * the drawer at one note per agent, so the screen's "most recent" read stays
     * unambiguous with no scheduler.
     *
     * Best-effort by design (TH-2 graceful miss): a note-write failure must NEVER drop a
     * live call. If the write throws (a DB hiccup), the call still connects; the recording
     * just won't attach (the row's correlation_id stays null, the audio persists on disk)
     * — exactly today's inbound fallback. We log and ring on.
     */
    private function ringAgent(int $reservedAgentId): void
    {
        rescue(
            fn () => TenantContext::run((int) $this->tenantId, function () use ($reservedAgentId): void {
                CallHandoff::query()->where('agent_user_id', $reservedAgentId)->delete();
                CallHandoff::query()->create([
                    'agent_user_id' => $reservedAgentId,
                    'ticket' => $this->ticketNumber,
                ]);
            }),
            function (Throwable $exception) use ($reservedAgentId): void {
                Log::warning('Ticket handoff write failed — inbound recording will not attach (the call is unaffected).', [
                    'ticket' => $this->ticketNumber,
                    'agentUser' => $reservedAgentId,
                    'error' => $exception->getMessage(),
                ]);
            },
        );

        $agentLegId = $this->telephony->placeCall(
            $this->directory->endpointFor($reservedAgentId),
            'agent',
            $this->callerNumber,
            $this->ringSeconds ?? (int) config('telephony.queue.ring_seconds'),
        );
        // The first agent enters the set RINGING, carrying the reservation so a no-answer
        // can release it (Fold B); it flips to connected when they pick up (connectAgent).
        $this->agents[$agentLegId] = new AgentLeg(
            $agentLegId,
            userId: $reservedAgentId,
            connected: false,
            reservedTenantId: $this->tenantId,
            reservedAgentId: $reservedAgentId,
        );
        $this->registry->registerLeg($agentLegId, $this);   // the leg we placed is ours (FD-2)
        $this->state = CallFlowState::RingingAgent;

        Log::info('Inbound call: reserved a free agent, caller answered, ringing them.', [
            'ticket' => $this->ticketNumber,
            'caller' => $this->callerLegId,
            'callerNumber' => $this->callerNumber,
            'tenant' => $this->tenantId,
            'agentUser' => $reservedAgentId,
            'agent' => $agentLegId,
            'ringSeconds' => $this->ringSeconds ?? (int) config('telephony.queue.ring_seconds'),
        ]);
    }

    /**
     * Put the caller in the waiting room (QD-1/QD-2): music starts on the line they are
     * already on, and the call is simply labelled Waiting. There is no queue object and
     * no holding bridge — the switchboard's own list of live calls, in arrival order, IS
     * the waiting line, so first-in-first-out comes free.
     *
     * Reached from both doors: nobody free when the call arrived, and an agent letting
     * their phone ring out. Each hands its own reason to the log, because they mean
     * different things to whoever reads it (understaffed vs one desk not answering).
     */
    private function enterWaitingRoom(string $why): void
    {
        $this->telephony->startHoldMusic((string) $this->callerLegId);
        $this->state = CallFlowState::Waiting;

        Log::info('Inbound call: the caller is holding with music on, waiting for a desk to free up.', [
            'ticket' => $this->ticketNumber,
            'caller' => $this->callerLegId,
            'tenant' => $this->tenantId,
            'why' => $why,
            'alreadyRung' => $this->rungAgentIds,
        ]);
    }

    /**
     * The waiting room's one move, driven by the listener's existing five-second
     * heartbeat (QD-3 — no scheduler, no queue worker, nothing new running). Either the
     * caller has now waited longer than this client allows and we stop waiting, or we
     * try the next free agent — and only when one is actually reserved does the music
     * stop, so a caller waiting behind a full floor never hears it stutter.
     *
     * A no-op on any call that is not waiting, which is every other call on the switch.
     *
     * Errors are handled exactly as they are for an incoming event (handle): a refused
     * verb tears down this one call, a lost pipe is everyone's problem and goes up. The
     * sweep is a second way IN to the same machine, so it must not be a second way to
     * fail. *Named ceiling:* a caller who hangs up in the instant between the sweep
     * reserving an agent and the music being stopped is torn down by that path instead
     * of by their own hang-up, and so leaves no missed-call record — a one-tick race,
     * accepted rather than guarded, and the reason the record is written on the ordinary
     * endings rather than on teardown in general.
     */
    public function tryAgain(): void
    {
        try {
            $this->sweepThisCall();
        } catch (AriConnectionLost $exception) {
            throw $exception;
        } catch (TelephonyException $exception) {
            $this->abort($exception);
        }
    }

    private function sweepThisCall(): void
    {
        if ($this->state !== CallFlowState::Waiting) {
            return;
        }

        if ($this->heldTooLong()) {
            $this->giveUpOnWaitingCaller();

            return;
        }

        $reservedAgentId = $this->reserveNextAgent();

        if ($reservedAgentId === null) {
            return;   // still nobody free — keep holding
        }

        $this->telephony->stopHoldMusic((string) $this->callerLegId);
        $this->ringAgent($reservedAgentId);
    }

    /**
     * Has this caller been on the line longer than this client allows without ever
     * reaching an agent (QD-7)? Measured from ARRIVAL, not from the moment the wait
     * started: a caller who spent forty seconds listening to one agent's phone ring and
     * then went back to the waiting room has been waiting the whole time, from their
     * side. It is the same clock the missed-call record's duration reports.
     */
    private function heldTooLong(): bool
    {
        $maxHoldSeconds = $this->maxHoldSeconds ?? (int) config('telephony.queue.max_hold_seconds');

        return $this->startedAt !== null
            && $this->startedAt->copy()->addSeconds($maxHoldSeconds)->isPast();
    }

    /**
     * We stopped waiting on the caller's behalf (QD-6 `no_answer`): end the call and
     * write the missed-call record. Disposing here is deliberate — the hang-up's own
     * ChannelDestroyed then lands on a handler the switchboard has already forgotten,
     * so it cannot write the record a second time.
     */
    private function giveUpOnWaitingCaller(): void
    {
        Log::info('Inbound call: the caller held longer than this client allows — ending the call as a missed one.', [
            'ticket' => $this->ticketNumber,
            'caller' => $this->callerLegId,
            'tenant' => $this->tenantId,
            'heldSeconds' => (int) $this->startedAt?->diffInSeconds(now()),
        ]);

        rescue(fn () => $this->telephony->hangup((string) $this->callerLegId), report: false);
        $this->recordMissedCall(CallOutcome::NoAnswer);
        $this->dispose();
    }

    /**
     * Write the call record for a caller no agent ever reached (QD-5) — the missed-call
     * list's row, and the ONE row the listener creates rather than enriches.
     *
     * This is not a break of the `calls` single-writer rule, it is that rule's own named
     * seam arriving: D2 (b3-calls-table.md, locked 2026-06-17) says abandoned and
     * unanswered inbound calls are listener-authored, trunk-era. The two writers cannot
     * collide on these rows — a screen writes a record when an agent wraps up a call they
     * handled, and this row exists precisely because no agent ever handled it.
     *
     * Without it these callers leave no trace at all, which is exactly the long-standing
     * Asterisk default this slice exists to avoid: the very people the waiting room is
     * built to save would be the ones invisible to every call report.
     *
     * Best-effort (rescue + tenant-scoped run, the ticket-handoff shape): a failed write
     * logs a warning and must never affect a live call. A no-op on an outbound call and
     * on an inbound one whose client was never known — both leave `tenantId` null.
     */
    private function recordMissedCall(CallOutcome $outcome): void
    {
        if ($this->tenantId === null || $this->startedAt === null) {
            return;
        }

        $tenantId = $this->tenantId;
        $startedAt = $this->startedAt;
        $endedAt = now();

        rescue(
            fn () => TenantContext::run($tenantId, fn () => Call::query()->create([
                'direction' => CallDirection::Inbound,
                'from_number' => $this->callerNumber,
                'to_number' => $this->dialledNumber,
                'outcome' => $outcome,
                'correlation_id' => $this->ticketNumber,
                'started_at' => $startedAt,
                'ended_at' => $endedAt,
                'duration_seconds' => (int) $startedAt->diffInSeconds($endedAt),
            ])),
            function (Throwable $exception) use ($outcome): void {
                Log::warning('Missed-call record write failed — the caller will not appear in the missed-call list.', [
                    'ticket' => $this->ticketNumber,
                    'outcome' => $outcome->value,
                    'error' => $exception->getMessage(),
                ]);
            },
        );
    }

    /**
     * Which company is this call for? (B2.3a ND-1.)
     *
     * The SOURCE changed, not the meaning: the dialplan used to stamp a hardcoded
     * company on every inbound call (RD-1, `Set(tenantid=1)`) — which is exactly why
     * a second client could never have a phone number. Now the dialplan only notes
     * WHICH NUMBER WAS DIALLED and the lookup happens here, against the phone_numbers
     * list. Every caller of this method, its return type and every downstream step are
     * untouched (the RD-1 promise).
     *
     * The note is read from the arrival event's channelvars (ND-3, verified live S85:
     * `dialednumber` arrives intact, NOT the "800" the call's extension reads as after
     * the dialplan's Goto). It rides as a channel variable rather than a Stasis
     * argument because Switchboard::onArrival classifies a new inbound call as
     * STRICTLY empty args — one extra argument and the call is silently dropped.
     *
     * Returns null for an unknown or switched-off number (ND-4 clean end).
     *
     * @param  array<string, mixed>  $event
     */
    private function companyLabel(array $event): ?int
    {
        return $this->numbers->resolve($this->dialledNumber($event))?->tenant_id;
    }

    /**
     * The number the caller actually dialled, as the dialplan wrote it down before
     * jumping to the front door (ND-3). Null when the note is missing — an inbound
     * call that never passed through the pattern rule.
     *
     * @param  array<string, mixed>  $event
     */
    private function dialledNumber(array $event): ?string
    {
        $number = $event['channel']['channelvars']['dialednumber'] ?? null;

        return ($number === null || $number === '') ? null : (string) $number;
    }

    /**
     * Outbound entry (B-outbound M2): the agent's leg is already up (the web
     * console placed it agent-first); now dial the customer. The customer maps
     * onto callerLegId so the join/record/teardown below reuse unchanged. The
     * bare number rides as a clean arg; the engine-specific endpoint prefix and
     * the single outbound caller-ID (O2) are read from config here.
     */
    private function beginOutboundCall(string $agentLegId, string $customerNumber, ?string $correlationId = null, ?int $servingAgentId = null): void
    {
        $this->correlationId = $correlationId;
        // Outbound reuses the web's tracking number as the ticket (FD-3); if a pre-B3
        // two-arg leg carried none, mint one so every call still has a unique ticket.
        $this->ticketNumber = $correlationId ?? (string) Str::uuid();
        // The agent's leg is already up and committed to this call, so it enters the set
        // CONNECTED (no reservation — they picked themselves). It carries the dialing
        // agent's user id (B2.4a thread, may be null on a pre-B2.4a leg) so an outbound
        // call is transferable too; the switchboard finds this handler by it. The
        // switchboard already registered this leg when it made the handler.
        $this->agents[$agentLegId] = new AgentLeg($agentLegId, userId: $servingAgentId, connected: true);

        $this->callerLegId = $this->telephony->placeCall(
            config('telephony.outbound.dial_prefix').$customerNumber.config('telephony.outbound.dial_suffix'),
            'outbound',
            config('telephony.outbound.caller_id'),
        );
        $this->registry->registerLeg($this->callerLegId, $this);   // the leg we placed is ours (FD-2)
        $this->state = CallFlowState::RingingCustomer;

        Log::info('Outbound call: agent connected, ringing the customer.', [
            'ticket' => $this->ticketNumber,
            'agent' => $agentLegId,
            'customer' => $this->callerLegId,
            'customerNumber' => $customerNumber,
        ]);
    }

    /**
     * The first agent picked up: put them and the caller in one conversation and record
     * both sides (D4). The sole agent leg flips ringing -> connected; its reservation is
     * cleared WITHOUT releasing (the agent answered, so the tag stays "On a call" and the
     * agent's own screen now owns the status). Reached by both directions — inbound's
     * agent answering, and outbound's customer answering the already-up agent.
     */
    private function connectAgent(): void
    {
        $agent = $this->soleAgent();

        if ($agent === null) {
            return;
        }

        $this->conversationId = $this->telephony->join((string) $this->callerLegId, $agent->legId);
        $this->recording = $this->telephony->startRecording(
            (string) $this->callerLegId,
            'call-'.now()->format('Ymd-His'),
        );
        $this->state = CallFlowState::InCall;

        // Flip ringing -> connected and clear (not release) the reservation. The serving
        // agent's user id already rode in with the AgentLeg, so there is nothing to copy
        // here (the B2.4a "copy before the wipe" dance is gone — each member owns its id).
        $agent->markConnected();

        // Register the pending merge the MOMENT recording starts — not at hang-up. When the
        // recorded (caller) leg drops, Asterisk destroys its taps and emits "recording
        // finished" immediately, BEFORE our teardown runs; if the switchboard's notebook does
        // not already hold this call, those events land on an empty desk and the merge is lost
        // (the CP-B2.1 inbound race). The call-id is the TICKET now (TH-4): outbound's
        // ticket already equals its web UUID (value unchanged), and inbound carries the
        // ticket too — the SAME id the screen stamped on the row via the handoff — so
        // both directions attach by matching ids. The raw caller leg id stays only as a
        // last-ditch fallback if a ticket was somehow never minted.
        $callId = $this->ticketNumber ?? (string) $this->callerLegId;
        $this->registry->depositMerge($callId, $this->recording);

        Log::info('Call connected and recording.', [
            'ticket' => $this->ticketNumber,
            'recording' => $this->recording->name,
        ]);
    }

    /**
     * Is THIS handler's live call currently served by the given agent (B2.4a TD-4,
     * B2.4b CD-2)? The switchboard scans live handlers with this to find "which call is
     * agent X on" — now a set-membership check across the CONNECTED agents, so it reaches
     * a 3-way conference by EITHER agent (a second conference click; supervisor-monitor
     * later). A ringing-but-not-yet-answered agent is not yet "serving" (no transfer
     * before connect — that rule lives in beginAddedAgent, not here).
     */
    public function isServingAgent(int $userId): bool
    {
        foreach ($this->agents as $agent) {
            if ($agent->connected && $agent->userId === $userId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Begin a cold transfer (B2.4a TD-5): ring a free agent (B) and, when they answer,
     * drop the current agent (A) — the caller ends up with B. A public name over the
     * shared add-an-agent ring (beginAddedAgent) so the switchboard + tests read clearly.
     */
    public function beginTransfer(int $tenantId): void
    {
        $this->beginAddedAgent($tenantId, AddedAgentIntent::Transfer);
    }

    /**
     * Begin a 3-way conference (B2.4b CD-3/CD-4): ring a free agent (B) and, when they
     * answer, ADD them while keeping the current agent (A) — caller + A + B all talking.
     * Identical ring to a transfer; only the on-answer action differs (no drop of A).
     */
    public function beginConference(int $tenantId): void
    {
        $this->beginAddedAgent($tenantId, AddedAgentIntent::Conference);
    }

    /**
     * Ring a free agent (B) into a live call while the caller stays with the current
     * agent(s) — the caller is never left alone (the never-strand rule, shared by cold
     * transfer and conference). B answering arrives as their agent-leg StasisStart and
     * forks on $intent (drop A vs keep A); B not answering ends as a ringing-leg
     * ChannelDestroyed and reuses the Fold-B release. Driven by the switchboard off the
     * web's transfer/conference signal (TD-4); the tenant is the signal's server-derived
     * one — A is provably in the call's company (A was reserved from that board, TD-6).
     */
    private function beginAddedAgent(int $tenantId, AddedAgentIntent $intent): void
    {
        // Only a connected call can take a second agent: there must be a live
        // conversation. Ignore a signal at any other time — a double-click while B
        // already rings (state AddingAgent), or after the call ended.
        if ($this->state !== CallFlowState::InCall) {
            return;
        }

        // Cap this slice at 3-way (CD-5): one caller + at most two connected agents. A
        // signal on an already-3-party call is a no-op — 4-way+ is a free later cap-lift
        // (the participant set already holds N), so capping now costs nothing future.
        if (count($this->connectedAgents()) >= 2) {
            return;
        }

        $reservedAgentId = $this->router->reserveFreeAgent($tenantId);

        // Nobody free (RD-5 flavour): touch nothing — the caller keeps the current
        // agent(s). The screen learns it via its own ring-window timeout (no
        // listener->screen DB signal, TD-5/CD-6).
        if ($reservedAgentId === null) {
            Log::info('Add agent: no free agent available — leaving the call with the current agent(s).', [
                'ticket' => $this->ticketNumber,
                'tenant' => $tenantId,
                'intent' => $intent->name,
            ]);

            return;
        }

        // B's leg carries no caller-ID in this slice (the customer number isn't retained
        // past the first ring; a nicety parked for later). It enters the set RINGING with
        // a FRESH reservation, so releaseReservation guards B's tag, never a connected
        // agent's (theirs were cleared at connect).
        $legId = $this->telephony->placeCall(
            $this->directory->endpointFor($reservedAgentId),
            'agent',
        );
        $this->agents[$legId] = new AgentLeg(
            $legId,
            userId: $reservedAgentId,
            connected: false,
            reservedTenantId: $tenantId,
            reservedAgentId: $reservedAgentId,
        );
        $this->registry->registerLeg($legId, $this);   // B's leg is ours (FD-2)
        $this->addedAgentIntent = $intent;
        $this->state = CallFlowState::AddingAgent;

        Log::info('Add agent: reserved a free agent, ringing them while the caller stays with the current agent(s).', [
            'ticket' => $this->ticketNumber,
            'tenant' => $tenantId,
            'intent' => $intent->name,
            'toAgent' => $reservedAgentId,
            'addedLeg' => $legId,
        ]);
    }

    /**
     * The added agent (B) picked up: fork on why they were rung (CD-5). The ring was
     * identical; only the completion differs — transfer drops the existing agent,
     * conference keeps them.
     */
    private function onAddedAgentAnswered(string $legId): void
    {
        match ($this->addedAgentIntent) {
            AddedAgentIntent::Transfer => $this->completeTransfer($legId),
            AddedAgentIntent::Conference => $this->completeConference($legId),
            null => null,
        };
    }

    /**
     * B picked up on a TRANSFER (B2.4a TD-5). Add-first then remove — slip B into the
     * live conversation BEFORE dropping the existing agent(s), so the caller never hears
     * a gap (the conversation is a mixing bridge, so it briefly holds caller + A + B).
     * Then hang up A (A's screen moves to wrap-up on its own). The recording is
     * untouched: its snoop sits on the caller leg, which never moved (TD-3/TD-6) — A's
     * part then B's part land as one continuous file.
     */
    private function completeTransfer(string $bLegId): void
    {
        $b = $this->agents[$bLegId];

        $this->telephony->addToBridge((string) $this->conversationId, $bLegId);

        foreach ($this->connectedAgents() as $a) {
            $this->telephony->removeFromBridge((string) $this->conversationId, $a->legId);
            $this->telephony->hangup($a->legId);
            unset($this->agents[$a->legId]);
        }

        $b->markConnected();
        $this->addedAgentIntent = null;
        $this->state = CallFlowState::InCall;

        Log::info('Transfer complete: the new agent is on the call; the previous agent has been dropped.', [
            'ticket' => $this->ticketNumber,
            'servingAgent' => $b->userId,
            'agentLeg' => $bLegId,
        ]);
    }

    /**
     * B picked up on a CONFERENCE (B2.4b CD-4). Add B and KEEP the existing agent — the
     * 3-way: caller + A + B all talking, the conversation's mixing bridge now holding all
     * three. The ENTIRE difference from a transfer is the absence of the remove + hang-up
     * of A. The recording rides through untouched (the caller-leg snoop never moves), so
     * one continuous file captures the whole conference.
     */
    private function completeConference(string $bLegId): void
    {
        $b = $this->agents[$bLegId];

        $this->telephony->addToBridge((string) $this->conversationId, $bLegId);

        $b->markConnected();
        $this->addedAgentIntent = null;
        $this->state = CallFlowState::InCall;

        Log::info('Conference: the added agent joined; all parties are now on the call.', [
            'ticket' => $this->ticketNumber,
            'agents' => array_map(fn (AgentLeg $a): ?int => $a->userId, $this->connectedAgents()),
        ]);
    }

    /**
     * A leg ended. What it means depends on where we are and which leg it was.
     *
     * Waiting (B2.3b-i): only the caller's own leg matters — they gave up while holding.
     * Record it as a missed call and let go; nothing else is left to tear down.
     *
     * Bootstrap ring (no conversation yet — establishing the FIRST connection): the
     * shape is direction-asymmetric. Inbound (RingingAgent): the agent leg ending is a
     * no-answer, which is QD-4's SECOND door — that caller used to be hung up on here
     * and now goes back to the waiting room instead; the caller leg ending is a caller
     * who gave up while the phone rang, which drops the ringing agent and is recorded
     * as a missed call (they are just as lost as one who gave up on hold). Outbound
     * (RingingCustomer): the agent leg is already up, so the CUSTOMER leg ending is the
     * no-answer (drop the agent), the agent leg ending is the agent abandoning mid-ring
     * (cancel the customer) — neither records anything, because the agent's own screen
     * owns an outbound call's row.
     *
     * Live call (InCall / AddingAgent — the participant-set rule, CD-2): the caller
     * leaving ends the whole call; a *ringing* added agent ending is a no-answer (release
     * + drop, the call carries on with whoever was connected); a *connected* agent ending
     * is a hang-up (drop them, continue if any connected agent remains, else the caller is
     * alone -> end). Snoop legs and a freshly-dropped survivor match no tracked id and
     * fall through untouched.
     *
     * @param  array<string, mixed>  $event
     */
    private function onLegEnded(array $event): void
    {
        $legId = $event['channel']['id'] ?? '';

        if ($this->state === CallFlowState::Waiting) {
            if ($legId === $this->callerLegId) {
                Log::info('Inbound call: the waiting caller gave up — writing them to the missed-call list.', [
                    'ticket' => $this->ticketNumber,
                    'tenant' => $this->tenantId,
                    'waitedSeconds' => (int) $this->startedAt?->diffInSeconds(now()),
                ]);

                $this->recordMissedCall(CallOutcome::Abandoned);
                $this->dispose();
            }

            return;
        }

        if ($this->state === CallFlowState::RingingAgent || $this->state === CallFlowState::RingingCustomer) {
            // Fold B: a reserved agent never connected — release on BOTH no-answer paths
            // (the agent rang out, or the caller abandoned mid-ring). A no-op for outbound.
            $this->releaseAllReservations();

            if (isset($this->agents[$legId])) {
                unset($this->agents[$legId]);

                // QD-4's second door: an inbound agent let their phone ring out. The
                // caller goes back to the waiting room rather than being cut off, and
                // the skip list keeps that same silent desk out of the next attempt.
                if ($this->state === CallFlowState::RingingAgent && $this->callerLegId !== null) {
                    $this->enterWaitingRoom('the agent we rang did not pick up');

                    return;
                }

                $this->telephony->hangup((string) $this->callerLegId);
                $this->dispose();
            } elseif ($legId === $this->callerLegId) {
                foreach (array_keys($this->agents) as $agentLegId) {
                    $this->telephony->hangup($agentLegId);
                }
                $this->recordMissedCall(CallOutcome::Abandoned);
                $this->dispose();
            }

            return;
        }

        if ($this->state === CallFlowState::InCall || $this->state === CallFlowState::AddingAgent) {
            // The caller leaving ends the whole call — never leave an agent (or a
            // still-ringing added agent) on a dead call. Release any held tag (a ringing
            // added agent, Fold B), then end, hanging up everyone still held.
            if ($legId === $this->callerLegId) {
                $this->releaseAllReservations();
                $this->endCall($legId);

                return;
            }

            $agent = $this->agents[$legId] ?? null;

            if ($agent === null) {
                return;   // not a leg we track (a snoop, a freshly-dropped survivor)
            }

            if (! $agent->connected) {
                // A ringing added agent ended (rang out / declined): release its tag
                // (Fold B), drop it, the call carries on with whoever was connected.
                $this->releaseReservation($agent);
                unset($this->agents[$legId]);
                $this->state = CallFlowState::InCall;

                Log::info('Add agent: the new agent did not answer — the call stays with the current agent(s).', [
                    'ticket' => $this->ticketNumber,
                ]);

                return;
            }

            // A connected agent hung up. Drop them — the leg is already gone, so no
            // removeFromBridge. If a connected agent still remains the call continues
            // (caller + the rest); otherwise the caller would be alone -> end the call,
            // hanging up any still-ringing added agent too (never strand the caller).
            unset($this->agents[$legId]);

            if ($this->connectedAgents() !== []) {
                Log::info('An agent left the call; it continues with the remaining agent(s).', [
                    'ticket' => $this->ticketNumber,
                ]);

                return;
            }

            $this->releaseAllReservations();
            $this->endCall($legId);
        }
    }

    /**
     * Tear the call down. The pending merge was already registered at connect time
     * (connectAgent), so the recording survives whoever hangs up first. Stopping the
     * recording here is best-effort: when the RECORDED (caller) leg is the one that
     * dropped (inbound hang-up), Asterisk has already finished and destroyed the taps,
     * so an explicit stop would refuse — expected, not an error. Hangs up every leg we
     * still hold except the one that just ended (the caller + any agents, including a
     * still-ringing added agent), then folds the conversation.
     */
    private function endCall(string $endedLegId): void
    {
        $recording = $this->recording;
        $conversationId = (string) $this->conversationId;
        $legsToHangUp = array_filter($this->allLegIds(), fn (string $legId): bool => $legId !== $endedLegId);

        if ($recording !== null) {
            rescue(fn () => $this->telephony->stopRecording($recording), report: false);
        }

        $this->dispose();

        foreach ($legsToHangUp as $legId) {
            rescue(fn () => $this->telephony->hangup($legId), report: false);
        }
        rescue(fn () => $this->telephony->endConversation($conversationId), report: false);
    }

    /** A verb was refused mid-call: drop any legs we still hold, then tear this call down. */
    private function abort(TelephonyException $exception): void
    {
        Log::warning('Call aborted after a telephony error; tearing down just this call.', [
            'ticket' => $this->ticketNumber,
            'error' => $exception->getMessage(),
        ]);

        // Release any reservation we hold so an error before an agent connects can't
        // leave them stuck "On a call" (Fold B lifecycle — the stale-heartbeat net won't
        // free a leaked tag while the tab keeps stamping). Best-effort: never mask the
        // original telephony error.
        rescue(fn () => $this->releaseAllReservations(), report: false);
        $this->hangupHeldLegs();
        $this->dispose();
    }

    /**
     * Tear this handler down for good: wipe its state and ask the switchboard to forget
     * its legs. Handlers are one-per-call now (FD-4) — never reused — so every teardown
     * path (no-answer reset, hang-up, abort) ends here.
     */
    private function dispose(): void
    {
        $this->reset();
        $this->registry->release($this);
    }

    /**
     * The switchboard's backstop caught an unexpected error on this call (FD-6):
     * best-effort drop any legs we still hold. The switchboard forgets us separately,
     * so this does not call the registry.
     */
    public function discard(): void
    {
        // Same reservation safety as abort() (Fold B lifecycle): the switchboard backstop
        // fires on an UNEXPECTED error, which could strike after we reserved an agent but
        // before they connected — release the tag so they are not stuck "On a call".
        rescue(fn () => $this->releaseAllReservations(), report: false);
        $this->hangupHeldLegs();
        $this->reset();
    }

    /** The sole agent on the call — used at connect, where exactly one agent exists. */
    private function soleAgent(): ?AgentLeg
    {
        return array_values($this->agents)[0] ?? null;
    }

    /**
     * The agents currently connected (in the conversation) — excludes a still-ringing
     * added agent. The serving set: who isServingAgent matches, and who a transfer drops.
     *
     * @return array<int, AgentLeg>
     */
    private function connectedAgents(): array
    {
        return array_values(array_filter($this->agents, fn (AgentLeg $agent): bool => $agent->connected));
    }

    /** Every leg we still hold — the caller plus all agent legs (ringing or connected). */
    private function allLegIds(): array
    {
        $legIds = array_keys($this->agents);

        if ($this->callerLegId !== null) {
            $legIds[] = $this->callerLegId;
        }

        return $legIds;
    }

    /** Best-effort hang up every leg we still hold (caller + all agent legs). */
    private function hangupHeldLegs(): void
    {
        foreach ($this->allLegIds() as $legId) {
            rescue(fn () => $this->telephony->hangup($legId), report: false);
        }
    }

    /**
     * Release one agent's board reservation if it still holds one (Fold B): used when a
     * reserved agent never connected. CONDITIONAL inside the router (flips on_call ->
     * ready only if still the tag we set), so it never overwrites a status the agent's
     * own screen set during the ring. Clears the refs so it is safe to call again.
     */
    private function releaseReservation(AgentLeg $agent): void
    {
        if ($agent->reservedTenantId === null || $agent->reservedAgentId === null) {
            return;
        }

        $this->router->releaseReservation($agent->reservedTenantId, $agent->reservedAgentId);
        $agent->reservedTenantId = null;
        $agent->reservedAgentId = null;
    }

    /** Release every reservation we still hold (a ringing inbound/added agent). */
    private function releaseAllReservations(): void
    {
        foreach ($this->agents as $agent) {
            $this->releaseReservation($agent);
        }
    }

    /** Wipe this call's state. Part of disposal — a handler is one-per-call now (B2.1). */
    private function reset(): void
    {
        $this->state = CallFlowState::Idle;
        $this->callerLegId = null;
        $this->agents = [];
        $this->addedAgentIntent = null;
        $this->conversationId = null;
        $this->correlationId = null;
        $this->ticketNumber = null;
        $this->recording = null;
        $this->tenantId = null;
        $this->callerNumber = null;
        $this->dialledNumber = null;
        $this->startedAt = null;
        $this->ringSeconds = null;
        $this->maxHoldSeconds = null;
        $this->rungAgentIds = [];
    }
}
