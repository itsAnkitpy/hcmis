<?php

declare(strict_types=1);

namespace App\Telephony\Flows;

use App\Enums\CallDirection;
use App\Enums\CallEndedBy;
use App\Enums\CallOutcome;
use App\Enums\ClosedHours;
use App\Enums\MissedReason;
use App\Models\Call;
use App\Models\CallHandoff;
use App\Models\Campaign;
use App\Models\Lead;
// The model, aliased: App\Support\PhoneNumber (the number formatter) already owns the
// plain name across the codebase (AgentConsole).
use App\Models\PhoneNumber as PhoneNumberRecord;
use App\Models\Tenant;
use App\Support\PhoneNumber;
use App\Telephony\AgentDirectory;
use App\Telephony\AgentRouter;
use App\Telephony\AriConnectionLost;
use App\Telephony\NumberDirectory;
use App\Telephony\RecordingSession;
use App\Telephony\TelephonyException;
use App\Telephony\TelephonyProvider;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
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
     * The supervisors listening in on this call right now (SM slice 2), keyed by their
     * own leg id. A COLLECTION rather than one pair: two supervisors on one call costs
     * barely more here than a scalar would, and SQ-3 warned this is the one thing that
     * is expensive to retrofit into the leg model once it has been assumed impossible.
     *
     * 🔴 A monitor is NOT a member of $agents and never becomes one. Everything that
     * decides who is on the call — isServingAgent, connectedAgents, the transfer's
     * "drop the current agent", the 3-way cap — walks that set, and a listener appearing
     * in it would be dropped by a transfer, counted towards the cap, and treated as
     * somebody the caller can hear. It is a separate set for exactly that reason.
     *
     * @var array<string, MonitorLeg>
     */
    private array $monitors = [];

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
     *
     * The client's hold-music name is read on the same trip (see below).
     */
    private ?int $ringSeconds = null;

    private ?int $maxHoldSeconds = null;

    /**
     * The name the voice box knows this client's own waiting-area music by (AU-13),
     * read alongside the two settings above and from the SAME client row, so per-client
     * music costs no extra database read. Null means this client uploaded none, and
     * null is what makes the caller hear the stock music.
     */
    private ?string $holdMusicClass = null;

    /**
     * The closed message currently playing to this caller (inbound-audio slice 4), by the
     * id the engine gave the play.
     *
     * 🔴 IT IS MATCHED, NOT ASSUMED. A "sound finished" event says which PLAY ended, not
     * why, and a play stopped by us reports the same `done` as one that ran out (S165).
     * Checking the id is what stops some other slice's sound — the waiting message, a menu
     * greeting — ending a call that was never playing a closed message.
     */
    private ?string $closedMessagePlaybackId = null;

    /**
     * The agents who have let THIS caller's ring run out, and the moment each one's phone
     * stopped ringing (QD-4's per-call skip list). Without it a waiting caller cycles
     * between hold music and the same silent desk: the agent's phone rings out, the board
     * still says Ready, and the next sweep picks them straight back up. Deliberately
     * per-call — taking a non-answering agent off the board for EVERYONE needs a sixth
     * presence state and a screen to clear it (B2.3b-ii).
     *
     * 🔴 IT COOLS OFF, IT DOES NOT EXILE (S88). The list used to be permanent for the
     * call, which quietly turned a small floor into a hard cap on how many rings a caller
     * could ever get: two agents on a twenty-second ring were both used up inside forty
     * seconds, and from then on the sweep could only ever come back with nobody — so the
     * caller held out the remaining hundred and forty seconds of the cap with no
     * possibility of connecting, and was then hung up on. The two people who could have
     * taken the call had been sitting there Ready the whole time.
     *
     * Cooling-off is also what every real queue does: Asterisk's own has a `retry` gap of
     * a few seconds before a member who did not answer is tried again, and only takes
     * them off the board when `autopause` is switched on deliberately
     * (https://github.com/asterisk/asterisk/blob/master/configs/samples/queues.conf.sample).
     *
     * Stamped when the ring ENDS rather than when it starts, so the cooling-off is a real
     * gap and not one the ring has already spent. One ring's worth long, borrowed from
     * this client's own ring setting rather than a new one to tune: an agent who missed
     * the phone gets exactly as long to come back as they had to answer.
     *
     * @var array<int, Carbon>
     */
    private array $rangOutAt = [];

    /**
     * An agent booked on the board but not yet carried by an AgentLeg (S88 review #1).
     * Booking an agent and building the leg that knows how to release them are two steps,
     * and anything throwing in between — most realistically the caller hanging up in that
     * same second, so the verb comes back "channel not found" — used to leave that agent
     * tagged On a call forever, because releaseAllReservations() iterates legs and there
     * is no leg yet. Their own screen's heartbeat keeps the stale-tag net from ever
     * clearing it, so nothing short of a database edit frees them.
     *
     * BOTH bookings pass through here, which is the whole point: the sweep's ring
     * (reserveNextAgent) and the second agent a transfer or conference rings in
     * (beginAddedAgent). The company is held alongside the agent because the pair is what
     * a release needs, and a transfer can happen on an OUTBOUND call, where the call's own
     * tenantId is null — the same pair every AgentLeg carries, for the same reason.
     */
    private ?int $pendingReservedTenantId = null;

    private ?int $pendingReservedAgentId = null;

    /**
     * Is hold music playing on the caller's line right now (S88 review #3)? The music
     * used to stop the moment the sweep reserved an agent, but the caller is NOT joined
     * to that agent until they pick up — so they heard silence for the whole ring, once
     * per desk tried. Twenty seconds of dead air reads as a dropped call, and the caller
     * hangs up; we then record that as "they gave up", which points the BPO at the hold
     * length when the real cause was that we played them nothing. Tracked so the music
     * runs unbroken from the first wait until the agent is actually on the line, and so
     * neither door starts a second copy of it.
     */
    private bool $holdMusicOn = false;

    /**
     * Hold — the caller parked mid-conversation (hold.md H-6). Three ordinary fields on
     * the handler, and deliberately NOT a sixth value in CallFlowState: the state is read
     * by the wall screen's tally, by the transfer and conference guards and by the
     * missed-call safety net, and the first thing a sixth value would break is the wall
     * screen — a held call would drop out of "calls in progress", which is wrong. It IS
     * in progress.
     *
     *   heldSince      when the current hold started; null whenever nobody is held
     *   heldSeconds    the running total PER AGENT (H-1), keyed by their user id
     *   heldByAgentId  whose note the current hold belongs to (H-12) — on a three-way the
     *                  hold belongs to whoever pressed it, and the other agent's row
     *                  carries no hold, because they did not hold anybody
     *
     * 🔴 Per agent, NOT per call (S112 review #1). A total kept per call and written to
     * one agent's note is the one arrangement no contact centre uses: the second agent to
     * press Hold would inherit the first agent's seconds, over-reporting Held and making
     * talkedSeconds() subtract music that played during somebody else's turn. Every
     * platform we checked stores hold against the agent leg and adds the legs up when it
     * wants a call figure — Amazon Connect's AgentInitiatedHoldDuration, Genesys Cloud's
     * per-segment hold, Cisco's per-leg Termination_Call_Detail. Our shape already suits
     * it: one note per agent, and each agent's own row on the Calls list (CT-12). The
     * whole-call total is SUM(hold_seconds) over one ticket, which is a query, not a
     * column.
     *
     * Anything later that needs to tell a held call from a talking one has the answer
     * already: `heldSince !== null`.
     */
    private ?Carbon $heldSince = null;

    /** @var array<int, int> agent user id => their own held seconds on this call */
    private array $heldSeconds = [];

    private ?int $heldByAgentId = null;

    /**
     * The lead this call was dialled from (DIAL-1 A3), held so a no-answer can count the
     * attempt against it. Null on every other kind of call — inbound has no lead until
     * the screen matches one, and the console's outbound counts its own attempt at
     * wrap-up.
     */
    private ?int $dialedLeadId = null;

    /**
     * The campaign this call was dialled for (DIAL-1 A4). Held for the one row that can
     * still be written after a dial connects — an abandon — because that row has to name
     * the campaign that placed the call, and our own caller-ID cannot be looked up to
     * find it. Null on every other kind of call.
     */
    private ?int $dialedCampaignId = null;

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
     * Where this call is in its lifecycle, and which client it belongs to (Call Stats
     * CS-1). The switchboard's tally is the only reader: it walks the live calls and
     * sorts them into the three numbers a team leader watches. Read-only — nothing
     * outside may set either, and a call whose client is not known yet (an inbound one
     * on an unrecognised number, a handler still Idle) reports null and is left out of
     * every count rather than added to a default.
     */
    public function state(): CallFlowState
    {
        return $this->state;
    }

    public function tenantId(): ?int
    {
        return $this->tenantId;
    }

    /**
     * When this caller arrived — the zero of the wait clock (Longest Wait LW-1). Read by
     * the switchboard's tally to find the caller who has been holding longest, and already
     * the anchor the maximum-hold cap measures from (heldTooLong), so the two agree by
     * construction rather than by a second stamp kept in step.
     *
     * Null on a CONSOLE outbound call, which never waits: the agent places it, and their
     * customer is not held. A DIALLED customer does wait — once they answer, the desk we
     * booked has to ring — so the dialer's arrival stamps this too (F16), from the moment
     * they came on the line and not from the moment we started ringing them. Also null for
     * as long as a dial is still ringing, which is why an unanswered one writes no row.
     * Read-only, like state() and tenantId().
     */
    public function startedAt(): ?Carbon
    {
        return $this->startedAt;
    }

    /**
     * Every desk this call is currently holding on the agent board (DIAL-1 R8): the one
     * booked before a leg exists (pendingReserved*, the dialer's and the waiting room's
     * gap) plus every agent leg, ringing or connected.
     *
     * Read by the reservation reaper to answer "is this On a call tag backed by a real
     * call". It is the whole of what this handler would hand back at teardown, so a desk
     * that appears here can never be reaped, and one that does not appear anywhere is
     * held by nothing in this process.
     *
     * @return array<int, int>
     */
    public function heldAgentIds(): array
    {
        $held = $this->pendingReservedAgentId === null ? [] : [$this->pendingReservedAgentId];

        foreach ($this->agents as $agent) {
            foreach ([$agent->userId, $agent->reservedAgentId] as $agentUserId) {
                if ($agentUserId !== null) {
                    $held[] = $agentUserId;
                }
            }
        }

        return array_values(array_unique($held));
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
                'PlaybackFinished' => $this->onPlaybackFinished($event),
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
     *   ['agent', <number>, <uuid?>, <agentId?>, <tenantId?>]
     *                        -> outbound entry: the agent leg, carrying the customer
     *                           number to dial next (agent-first, CP-O0), the call's
     *                           tracking number (the B3 UUID), the serving agent's
     *                           user id (B2.4a, so an outbound call is transferable),
     *                           and the client the console was sitting in (CS-4).
     *   ['outbound']         -> outbound customer pickup (the customer leg we placed).
     *   ['monitor']          -> a supervisor's own phone answering, so we can tap the
     *                           agent's line and feed it to them (SM slice 2).
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

        if ($args === ['monitor']) {
            // A supervisor picked up the phone we rang them on (SM slice 2). Their
            // browser answers by itself (R1), so this lands a moment after the button
            // was pressed with nobody having clicked anything.
            $this->onMonitorAnswered($legId);

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
        // customer's number (arg 1), the call's UUID (arg 2), the serving agent's user
        // id (arg 3), and the client the console dialled from (arg 4, CS-4). >= 2
        // tolerates every shape from the pre-B3 two-arg leg upwards — each trailing
        // detail is optional, default null.
        if (is_array($args) && count($args) >= 2 && $args[0] === 'agent' && $this->state === CallFlowState::Idle) {
            $this->beginOutboundCall(
                $legId,
                (string) $args[1],
                isset($args[2]) ? (string) $args[2] : null,
                isset($args[3]) ? (int) $args[3] : null,
                isset($args[4]) ? (int) $args[4] : null,
            );

            return;
        }

        if ($args === ['outbound']) {
            if ($this->state === CallFlowState::RingingCustomer && $legId === $this->callerLegId) {
                $this->connectAgent();
            }

            return;
        }

        if ($args === ['dialer']) {
            // The progressive dialer's customer picked up (DIAL-1 A4 / DP-8). Their line
            // is up because THEY answered it, so nothing is answered here — the console's
            // outbound pickup just above leaves its customer leg alone for the same
            // reason.
            //
            // Ring the desk booked before the number was dialled (DQ-3). From this line
            // on it is the inbound path unchanged — music, screen pop, no-answer release,
            // waiting-room fallback, bridge and recording — which is what DF-2's reframe
            // promised and why DP-8 costs almost nothing.
            if ($this->state === CallFlowState::DialingCustomer
                && $legId === $this->callerLegId
                && $this->pendingReservedAgentId !== null) {
                Log::info('Progressive dial: the customer answered — ringing the desk held for them.', [
                    'ticket' => $this->ticketNumber,
                    'tenant' => $this->tenantId,
                    'customer' => $this->callerLegId,
                    'agentUser' => $this->pendingReservedAgentId,
                ]);

                // 🔴 THE WAIT CLOCK STARTS HERE, not when the number was dialled (F16).
                // `startedAt` means one thing everywhere that reads it — the moment this
                // customer came onto the line — and three things measure from it: the
                // maximum-hold cap (heldTooLong), the board's Longest Wait
                // (Switchboard::tallyByTenant) and the abandon row's "waited for". Stamped
                // at the dial instead, every one of them counts the seconds the phone was
                // ringing into an empty room as hold time the customer served. Measured:
                // on a 60-second cap, a customer who took 40 seconds to answer was hung up
                // 21 seconds into a hold they were owed 60 of, and filed as a no-answer
                // rather than being allowed to hold out and abandon on their own.
                $this->startedAt = now();

                $this->ringAgent($this->pendingReservedAgentId);
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
        // CH-5: normalized HERE, at the one place the caller's number enters this object,
        // so every later reader gets the clean value — the agent leg's caller-ID, the
        // handoff note, and the `from_number` this flow writes for a caller nobody
        // answered. The console normalizes its side (lookupLead / dialAdhoc) and the
        // calls migration already promises the column holds a normalized value; this
        // writer was the one that did not, so an unanswered inbound call stored `(0181)
        // 123 4567` where every lookup asks for `01811234567` and finds nothing.
        $this->callerNumber = PhoneNumber::normalize($callerNumber);
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
        $tenant = $this->loadQueueSettings($tenantId);

        // inbound-audio slice 1: the office hours sign, read BEFORE answering — the dialplan
        // hands the call over unanswered, so "don't pick up" is still possible here (AU-2).
        // Closed beats a Ready agent (AU-5). "busy" because no reason sends "declined",
        // which networks play as "not in service". A missing client row reads as open.
        if ($tenant?->isClosedAt(now()) === true) {
            // 🔴 THE ROW IS WRITTEN FIRST, BEFORE EITHER ENDING. AU-3 puts a closed-hours
            // caller on Missed Calls whether or not they heard a message, and writing it
            // here means no ending can lose it — not the message finishing, not the caller
            // hanging up halfway through it, not a listener restart mid-message. Both
            // branches below only decide what the LINE does.
            $this->recordMissedCall(CallOutcome::NoAnswer, MissedReason::ClosedHours);

            // Slice 4: the client has chosen to say why. Answer first, then play (AU-21's
            // order is unchanged — the hours were read before anything was picked up).
            // Playing into an unanswered leg would send early audio instead of answering,
            // and the carrier is still undecided (D-004), so we pick up.
            $closedMessageUrl = $tenant->settings->closedHours === ClosedHours::Message
                ? $tenant->closedMessageUrl()
                : null;

            if ($closedMessageUrl !== null) {
                $this->state = CallFlowState::PlayingClosedMessage;
                $this->telephony->answer($callerLegId);
                $this->closedMessagePlaybackId = $this->telephony->play($callerLegId, [$closedMessageUrl]);

                Log::info('Inbound call: the client is closed — answering and playing their closed message.', [
                    'ticket' => $this->ticketNumber,
                    'tenant' => $tenantId,
                    'caller' => $callerLegId,
                ]);

                return;
            }

            // No message to play, so do not answer into silence: end it unanswered with
            // the busy reason (AU-2). The default sends "declined", which networks often
            // play as "not in service". This is also the safety net for a client set to
            // "closed means a message" whose file has gone — the edit form refuses that
            // combination, but a caller must never be picked up and hung up on in silence.
            rescue(fn () => $this->telephony->hangup($callerLegId, 'busy'), report: false);
            Log::info('Inbound call: the client is closed — ending the call unanswered as busy.', [
                'ticket' => $this->ticketNumber,
                'tenant' => $tenantId,
                'caller' => $callerLegId,
            ]);
            $this->dispose();

            return;
        }

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
     *
     * Returns the client row it read, so the inbound door can check its hours without a
     * second lookup.
     */
    private function loadQueueSettings(int $tenantId): ?Tenant
    {
        $tenant = Tenant::query()->find($tenantId);

        $this->ringSeconds = $tenant?->ringSeconds();
        $this->maxHoldSeconds = $tenant?->maxHoldSeconds();
        $this->holdMusicClass = $tenant?->holdMusicClass();

        return $tenant;
    }

    /**
     * Reserve the next agent to try for this caller (RD-3/RD-4), skipping anyone whose
     * phone this caller has rung out in the last little while (QD-4). Null means nobody
     * is free right now — which starts the wait on a new call, and simply continues it on
     * a sweep.
     */
    private function reserveNextAgent(): ?int
    {
        $reservedAgentId = $this->router->reserveFreeAgent((int) $this->tenantId, $this->coolingOffAgentIds());

        if ($reservedAgentId !== null) {
            $this->pendingReservedTenantId = $this->tenantId;
            $this->pendingReservedAgentId = $reservedAgentId;
        }

        return $reservedAgentId;
    }

    /**
     * The agents to leave out of the next attempt: everyone whose ring for this caller
     * ran out less than one ring ago (S88). Once that has passed they are ordinary
     * candidates again — which is the whole difference between a desk we are giving a
     * moment's peace and a desk we have written off for the rest of the call.
     *
     * @return array<int, int>
     */
    private function coolingOffAgentIds(): array
    {
        $cooledOffBefore = now()->subSeconds($this->ringSecondsOrDefault());

        return array_keys(array_filter(
            $this->rangOutAt,
            fn (Carbon $rangOutAt): bool => $rangOutAt->isAfter($cooledOffBefore),
        ));
    }

    /** How long ONE agent's phone rings for this client, or the config default (QD-7). */
    private function ringSecondsOrDefault(): int
    {
        return $this->ringSeconds ?? (int) config('telephony.queue.ring_seconds');
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
        // Music from the moment we answer, including the very first ring (S88). The caller
        // is never joined to a ringing agent, so without this they get silence for the
        // whole ring window. Industry-checked: Asterisk's own queue plays music while it
        // rings agents by default and offers a ringing tone as the opt-out — silence is
        // not an option anyone ships, because it reads as a dropped call. Music over a
        // ringing tone because a ringing tone promises an answer in seven or eight rings,
        // and this caller may be handed past several desks.
        $this->startHoldMusicIfSilent();

        // The caller's arrival travels with the ticket (CT-2/CT-3). The note's own
        // created_at is the ring moment — it is written immediately before the phone
        // rings, so that moment needs no column of its own.
        // $arrivedAt is the caller's WAIT, and a dialled customer did not have one — we
        // rang them and they picked up (A4/D2). Passing startedAt there would file the
        // dial-to-answer seconds as hold time on every progressive call, inflating the
        // average wait on exactly the calls that had none. beginOutboundCall passes null
        // for the same reason. dialedLeadId is set only by the dialer, so it is already
        // the question "did this customer wait for us".
        $this->writeHandoffNote($reservedAgentId, $this->dialedLeadId === null ? $this->startedAt : null);

        // 🔴 F28/F30: A REFUSED DESK IS NOT A BROKEN CALL. The switch can refuse to ring
        // an endpoint it has a stale registration for (F29, measured 2026-09-13: a WebRTC
        // contact that had gone away without Asterisk noticing, HTTP 500 "Allocation
        // failed"). Without this catch the exception reaches abort(), which hangs up every
        // leg — so a dialled customer who had just ANSWERED got dead air, and the state
        // was still DialingCustomer, which recordMissedCallIfNeverConnected() skips: no
        // row, no counted attempt, and an abandoned call missing from both halves of
        // DP-12a's 3% cap, in the direction that flatters us.
        //
        // Caught HERE rather than in the dialer's branch because all three ways a desk is
        // rung pass through this one call — the dialer's answered customer, an inbound
        // call's first ring (beginCall), and the waiting room's retry (sweepThisCall) —
        // and the first two both landed in abort() from a state that writes nothing.
        // beginCall never assigns a state at all, so an answered INBOUND caller vanished
        // the same way (F30) and nobody had noticed, because no one reads abandon figures
        // for inbound calls. One guard, three paths.
        //
        // A lost pipe still goes up, as everywhere: that is everyone's problem, not this
        // call's (the tryAgain/handle split).
        try {
            $agentLegId = $this->telephony->placeCall(
                $this->directory->endpointFor($reservedAgentId),
                'agent',
                $this->callerNumber,
                $this->ringSecondsOrDefault(),
            );
        } catch (AriConnectionLost $exception) {
            throw $exception;
        } catch (TelephonyException $exception) {
            Log::warning('The desk we booked could not be rung — the customer keeps holding while we try another.', [
                'ticket' => $this->ticketNumber,
                'tenant' => $this->tenantId,
                'agentUser' => $reservedAgentId,
                'wasDialled' => $this->dialedLeadId !== null,
                'error' => $exception->getMessage(),
            ]);

            // Same note as a desk that let the phone ring out (QD-4's second door): it
            // keeps this desk out of the next attempt for one ring and then lets it back
            // in. Without it tryAgain() re-books the same refusing endpoint every five
            // seconds until the hold limit, and on a floor with someone else free the
            // customer would wait behind a phone that cannot ring instead of being
            // handed to them.
            $this->rangOutAt[$reservedAgentId] = now();

            // The booking is still PENDING here — it moves onto the leg only below, on
            // the line the throw skipped — so nothing else would ever hand it back and
            // this agent would sit tagged "On a call" for good (Fold B). Best-effort, for
            // the reason abort() rescues the same call: it must not become a second error.
            rescue(fn () => $this->releaseAllReservations(), report: false);

            // Close the screen pop we opened three lines ago for a phone that never rang.
            // It used to clear itself within milliseconds because the call died; now the
            // call lives on for the whole hold limit, so without this the agent watches a
            // customer's details for a call they are not on while someone else takes it.
            $this->stampHandoff(['ended_at' => now()], $reservedAgentId);

            // Back to the machinery that already handles "nobody can take this caller":
            // music stays on, the listener's five-second heartbeat retries, and if nobody
            // else is free the hold limit files them through giveUpOnWaitingCaller —
            // `abandoned` for a dialled customer (F22), counted attempt and all. The
            // bookkeeping is not rebuilt here; it is inherited.
            $this->enterWaitingRoom('the desk we booked could not be rung');

            return;
        }
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
        $this->pendingReservedTenantId = null;              // the leg carries the reservation now
        $this->pendingReservedAgentId = null;
        $this->state = CallFlowState::RingingAgent;

        Log::info('Ringing a free agent: the caller is on the line and holding.', [
            'ticket' => $this->ticketNumber,
            'caller' => $this->callerLegId,
            'callerNumber' => $this->callerNumber,
            'tenant' => $this->tenantId,
            'agentUser' => $reservedAgentId,
            'agent' => $agentLegId,
            'ringSeconds' => $this->ringSecondsOrDefault(),
        ]);
    }

    /**
     * Drop a handoff note for an agent we are about to ring (TH-5/TH-6): prune their
     * prior note, then write this call's ticket, so their screen reads the RIGHT call's
     * ticket at ring-time and stamps it on the row for the recording to attach to.
     *
     * $arrivedAt is the caller's arrival, and it is deliberately null for the second
     * agent on a transfer or conference (CT-5): when the call reached them the customer
     * was not in the waiting room, they were mid-conversation. Copying the arrival
     * across would read as "waited 5m30s" for someone who waited 30 seconds, inflating
     * the average wait most on exactly the calls that got the most attention.
     *
     * Best-effort by design (TH-2 graceful miss): a note-write failure must NEVER drop a
     * live call. If the write throws (a DB hiccup), the call still connects; that row
     * just gets no timing and no recording — the same graceful degradation we already
     * accept for a recording that does not attach. We log and ring on.
     */
    private function writeHandoffNote(int $agentUserId, ?Carbon $arrivedAt): void
    {
        // No client, nothing to scope the write to — our own global staff dialling out
        // with no client in scope (CS-4's null case). That call stays out of the timing
        // figures rather than being filed under a made-up client.
        if ($this->tenantId === null) {
            return;
        }

        rescue(
            fn () => TenantContext::run((int) $this->tenantId, function () use ($agentUserId, $arrivedAt): void {
                CallHandoff::query()->where('agent_user_id', $agentUserId)->delete();
                CallHandoff::query()->create([
                    'agent_user_id' => $agentUserId,
                    'ticket' => $this->ticketNumber,
                    // CE-6: which of our numbers the caller rang. We know it here and
                    // nowhere downstream — it is read off the arrival event and would
                    // otherwise die with the call, which is why most inbound rows
                    // currently export with a blank Campaign. Null on outbound, where
                    // nobody dialled in to us at all.
                    'dialled_number' => $this->dialledNumber,
                    // 🔴 WHO PLACED THIS CALL (F15). The console writes the row's
                    // direction, and a dialled customer arrives on the agent's screen as
                    // an ordinary ring — so without this every answered dial was filed as
                    // a call the customer made to us. dialedLeadId is set by the dialer
                    // and by nothing else, so it already IS the question.
                    'was_dialled' => $this->dialedLeadId !== null,
                    'arrived_at' => $arrivedAt,
                ]);
            }),
            function (Throwable $exception) use ($agentUserId): void {
                Log::warning('Ticket handoff write failed — that row will carry no timing and the recording will not attach (the call is unaffected).', [
                    'ticket' => $this->ticketNumber,
                    'agentUser' => $agentUserId,
                    'error' => $exception->getMessage(),
                ]);
            },
        );
    }

    /**
     * Stamp a moment onto this call's handoff note(s) — the timing carrier (CT-3). The
     * listener owns every moment on a call but must not write the calls row (D2's
     * single-writer rule), so it leaves them here and the agent's screen copies them
     * across at wrap-up. One clock for all four, so they can never disagree.
     *
     * 🔴 Matched on the TICKET, never on the agent alone (CT-11). A note is filed under
     * an agent and lingers between calls, so "this agent's newest note" can belong to a
     * different call — an agent who let their phone ring out is still holding that
     * caller's arrival time. Pass $agentUserId to narrow to one agent's own note (their
     * pickup, their end); omit it to reach every note on this call, which is what the
     * hang-up needs on a conference where two agents are still connected (CT-12).
     *
     * An end never overwrites an end already there — FIRST CLOSE WINS (CT-12a). An
     * agent's part can finish well before the call does: dropped by a transfer, or
     * hanging up out of a conference the others carry on with. The teardown then fills
     * in only whoever is still open, and every ordering lands the same way.
     *
     * Best-effort, exactly like the note write itself (TH-2): a database hiccup must
     * never disturb a live call. A missed stamp costs one blank on one report row.
     *
     * @param  array<string, Carbon|CallEndedBy|int>  $moments
     */
    private function stampHandoff(array $moments, ?int $agentUserId = null): void
    {
        $ticket = $this->ticketNumber;

        if ($ticket === null || $this->tenantId === null) {
            return;
        }

        rescue(
            fn () => TenantContext::run((int) $this->tenantId, function () use ($moments, $agentUserId, $ticket): void {
                CallHandoff::query()
                    ->where('ticket', $ticket)
                    ->when($agentUserId !== null, fn (Builder $query) => $query->where('agent_user_id', $agentUserId))
                    ->when(isset($moments['ended_at']), fn (Builder $query) => $query->whereNull('ended_at'))
                    ->update($moments);
            }),
            function (Throwable $exception) use ($moments, $ticket): void {
                Log::warning('Call-timing stamp failed — this call will be missing a moment on its report row (the call itself is unaffected).', [
                    'ticket' => $ticket,
                    'moments' => array_keys($moments),
                    'error' => $exception->getMessage(),
                ]);
            },
        );
    }

    /**
     * Put the customer in the waiting room (QD-1/QD-2): music starts on the line they are
     * already on, and the call is simply labelled Waiting. There is no queue object and
     * no holding bridge — the switchboard's own list of live calls, in arrival order, IS
     * the waiting line, so first-in-first-out comes free.
     *
     * Reached from three doors: nobody free when the call arrived, an agent letting their
     * phone ring out, and (S154) the switch refusing to ring the desk we booked. Each
     * hands its own reason to the log, because they mean different things to whoever
     * reads it — understaffed, one desk not answering, one desk unreachable.
     *
     * 🔴 A DIALLED customer reaches all three, which is why neither this line nor the two
     * endings below says "inbound" any more: on a progressive dial the person holding is
     * someone WE rang, and a log that calls them "the caller" on an "inbound call" is the
     * one a reader skips past while hunting a dialer fault. `wasDialled` is the field to
     * filter on instead.
     */
    private function enterWaitingRoom(string $why): void
    {
        $this->startHoldMusicIfSilent();
        $this->state = CallFlowState::Waiting;

        Log::info('The customer is holding with music on, waiting for a desk to free up.', [
            'ticket' => $this->ticketNumber,
            'caller' => $this->callerLegId,
            'tenant' => $this->tenantId,
            'wasDialled' => $this->dialedLeadId !== null,
            'why' => $why,
            'rangOut' => array_keys($this->rangOutAt),          // everyone who has missed this caller
            'coolingOff' => $this->coolingOffAgentIds(),        // …and who is out of the next attempt
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
        // The client's maximum hold is read while a desk is RINGING too (S88 review #4).
        // It used to be read only while the caller was waiting, so a ring already running
        // when the cap passed always ran to its own end first — the configured maximum
        // overshot by a whole ring duration, every single time. A ring is still STARTED
        // right up to the cap, because an agent picking up in its first seconds still
        // saves that call; only a ring that has already run past the cap is cut.
        if ($this->state === CallFlowState::RingingAgent) {
            if ($this->heldTooLong() && ! $this->deskAlreadyAnswered()) {
                $this->giveUpOnWaitingCaller();
            }

            return;
        }

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

        // The music deliberately keeps playing through the ring (S88 review #3). The
        // caller is not joined to this agent until they pick up, so stopping it here
        // bought the caller silence for the whole ring window, once per desk tried.
        // connectAgent() stops it at the moment the two are actually on one line.
        $this->ringAgent($reservedAgentId);
    }

    /**
     * Start the hold music unless it is already playing (S88). Both the waiting-room doors
     * and the ring reach this, and every one of them can be entered with the music already
     * running — a second copy is never what we mean.
     */
    private function startHoldMusicIfSilent(): void
    {
        if ($this->holdMusicOn) {
            return;
        }

        $this->telephony->startHoldMusic((string) $this->callerLegId, $this->holdMusicClass);
        $this->holdMusicOn = true;
    }

    /** Stop the hold music if we started it; a no-op on every call that never waited. */
    private function stopHoldMusicIfPlaying(): void
    {
        if (! $this->holdMusicOn) {
            return;
        }

        $this->telephony->stopHoldMusic((string) $this->callerLegId);
        $this->holdMusicOn = false;
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
     * F20, found live on staging: the hold limit ran out in the same second the agent
     * picked up. The pick-up reaches us as an event, and the listener sweeps before it
     * hands over the event it is holding — so the limit won, the customer was hung up on,
     * the agent's console still saw a call and wrapped it up, and one call left two rows
     * and counted two tries. Asking Asterisk lets a pick-up already made win; its own
     * arrival then connects the call as normal.
     *
     * A failed lookup (the leg already gone) reads as "not picked up" — the limit's usual
     * ending. A lost line still goes up, as everywhere. ponytail: an agent answering in
     * the milliseconds between this answer and the hang-up still races the limit; the
     * window was up to one listener wait (5s) and is now one round trip.
     */
    private function deskAlreadyAnswered(): bool
    {
        $agent = $this->soleAgent();

        if ($agent === null) {
            return false;
        }

        try {
            return $this->telephony->isAnswered($agent->legId);
        } catch (AriConnectionLost $exception) {
            throw $exception;
        } catch (TelephonyException) {
            return false;
        }
    }

    /**
     * We stopped waiting on the caller's behalf (QD-6 `no_answer`): end the call and
     * write the missed-call record. Disposing here is deliberate — the hang-up's own
     * ChannelDestroyed then lands on a handler the switchboard has already forgotten,
     * so it cannot write the record a second time.
     *
     * Reached with a desk mid-ring now, not only from the wait (S88 review #4), so it
     * drops every leg we hold rather than the caller's alone — otherwise that agent's
     * phone would go on ringing for a caller who is no longer there — and hands their
     * board tag back first, so the cap firing cannot leave them tagged "On a call".
     *
     * A customer the DIALER rang is filed `abandoned` instead (F22) — recordMissedCall()
     * decides that, in the one place every missed-call path goes through.
     */
    private function giveUpOnWaitingCaller(): void
    {
        Log::info('The customer held longer than this client allows — ending the call as a missed one.', [
            'ticket' => $this->ticketNumber,
            'caller' => $this->callerLegId,
            'tenant' => $this->tenantId,
            'wasDialled' => $this->dialedLeadId !== null,
            'heldSeconds' => (int) $this->startedAt?->diffInSeconds(now()),
            'aDeskWasRinging' => $this->state === CallFlowState::RingingAgent,
        ]);

        rescue(fn () => $this->releaseAllReservations(), report: false);
        $this->hangupHeldLegs();
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
     * logs a warning and must never affect a live call. A no-op on an inbound call whose
     * client was never known (`tenantId` null — an unrecognised number).
     *
     * 🔴 A CONSOLE outbound call is still a no-op here, but a DIALLED one is not (A4).
     * The console's own outbound call is kept out by two things that both still hold:
     * `startedAt` is set only on the inbound path (beginCall) and the guard below reads
     * it, and the other way in — recordMissedCallIfNeverConnected() — fires only from
     * Waiting or RingingAgent, neither of which a console outbound call reaches. Its row
     * belongs to the agent's own screen at wrap-up (D2); writing one here would duplicate
     * it.
     *
     * A progressive dial breaks both of those: answering stamps `startedAt` (onArrival's
     * `dialer` branch — F16 moved it there from the dial itself), and a dialled customer
     * who answers DOES reach RingingAgent and the waiting room, where no agent's screen is
     * open to write anything. So its row is written here — and written as what it is,
     * which is the note on `direction` below.
     *
     * An UNANSWERED dial gets a row too (F21, reversing A3's "no row"): nobody came on the
     * line, so nothing stamped `startedAt`, but DP-12a divides by every dial placed and a
     * ring-out was one. The guard lets it through on `dialedLeadId`, which a console
     * outbound call never has, so that call is still kept out.
     */
    private function recordMissedCall(CallOutcome $outcome, ?MissedReason $reason = null): void
    {
        if ($this->tenantId === null || ($this->startedAt === null && $this->dialedLeadId === null)) {
            return;
        }

        $tenantId = $this->tenantId;
        $startedAt = $this->startedAt;
        $endedAt = now();
        // 🔴 WHICH WAY ROUND THIS CALL WENT (DIAL-1 A4/D3). A customer the dialer rang,
        // who answered and then gave up while holding, is a genuine abandoned call — it
        // is the number DP-12a measures against the 3% legal cap, and it has to be
        // written. It must not be written as INBOUND. Every reader picks the customer's
        // side of a call off this column (Call::forCustomer, the export's `customer`, the
        // calls list), and the Missed Calls page shows inbound rows only — so filed the
        // old way, a customer WE rang turns up on a supervisor's screen as somebody who
        // rang US and gave up, and gets called back for a call they never made.
        $wasDialled = $this->dialedLeadId !== null;
        // 🔴 F22. TRAI's abandoned call is one the person ANSWERED and no agent reached,
        // however it ended. So a dialled customer who came on the line is filed
        // `abandoned` whether they hung up or we did: the hold limit and an error teardown
        // pass NoAnswer, QD-6's mapping, which stays right for inbound. `$outcome` still
        // says who ended it, and that is what `ended_by` records below. A ring-out never
        // came on the line, so it stays `no_answer` (F9).
        $filedOutcome = $wasDialled && $startedAt !== null ? CallOutcome::Abandoned : $outcome;

        rescue(
            fn () => TenantContext::run($tenantId, fn () => Call::query()->create([
                'direction' => $wasDialled ? CallDirection::Outbound : CallDirection::Inbound,
                'was_dialled' => $wasDialled,
                // Ours first on a dial, theirs first on an inbound call — the pairing the
                // console's own wrap-up writes, so one rule reads both.
                'from_number' => $wasDialled ? $this->dialledNumber : $this->callerNumber,
                'to_number' => $wasDialled ? $this->callerNumber : $this->dialledNumber,
                // The lead we dialled, so the abandon can be traced back to the number it
                // came from. Null on an inbound call — nothing has matched a lead yet.
                'lead_id' => $this->dialedLeadId,
                // CE-6, the half the answered path already had. A call nobody picked up
                // still rang a known number, and "which campaign is losing callers" is
                // the question these rows exist to answer — so leaving Campaign blank
                // emptied the column on exactly the rows a supervisor groups by. Inside
                // the tenant run below, so the lookup is scoped to this client.
                //
                // A dialled call is told outright which campaign placed it, and takes
                // that: the lookup below reads the INBOUND number map, and the number a
                // dial presents is our outbound caller-ID, which is either missing from
                // that map or pointing at whichever campaign happens to receive on it.
                'campaign_id' => $this->dialedCampaignId ?? PhoneNumberRecord::campaignIdFor($this->dialledNumber),
                'outcome' => $filedOutcome,
                'correlation_id' => $this->ticketNumber,
                // CE-11, and this row is the one place we can say it without a note:
                // `abandoned` means the caller gave up, `no_answer` means we stopped
                // waiting on their behalf when the hold ran out (QD-6's mapping). The
                // two are the whole reason `System` exists as a value — "nobody hung
                // up, we ended it" is a different fact from "we do not know".
                'ended_by' => $outcome === CallOutcome::Abandoned
                    ? CallEndedBy::Customer
                    : CallEndedBy::System,
                'started_at' => $startedAt,
                // Both moments, no duration (CT-1/CT-4). `duration_seconds` used to be
                // written here and shown as "Waited for" on Missed Calls while the same
                // column read "Duration" on the Calls list — one field, two meanings,
                // invisible only because nothing else ever filled it. The wait is now
                // computed from these two moments wherever it is shown.
                'ended_at' => $endedAt,
                'missed_reason' => $reason,
            ])),
            function (Throwable $exception) use ($filedOutcome): void {
                Log::warning('Missed-call record write failed — the caller will not appear in the missed-call list.', [
                    'ticket' => $this->ticketNumber,
                    'outcome' => $filedOutcome->value,
                    'error' => $exception->getMessage(),
                ]);
            },
        );

        // F17: a dialled customer who came on the line but never reached an agent is a try
        // too. Uncounted, their lead sat at attempts 0 and was rung again the moment its
        // 90-second claim lapsed. Rescued because discard() reaches here, and a DB error
        // must not skip hanging up the held legs. Inbound calls have no lead — a no-op.
        rescue(fn () => $this->countDialAttempt());
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
     *
     * The client rides in on the label too (CS-4). An outbound call used to belong to
     * nobody, so the live call counts would have silently dropped every one of them —
     * and the Active number would be wrong on any floor doing outbound work. The console
     * that dialled was already sitting in one client, so it simply says which; nothing is
     * looked up or guessed here. Null when our own global staff dial with no client in
     * scope, which leaves that call out of the counts rather than in a made-up one.
     */
    private function beginOutboundCall(string $agentLegId, string $customerNumber, ?string $correlationId = null, ?int $servingAgentId = null, ?int $tenantId = null): void
    {
        $this->correlationId = $correlationId;
        $this->tenantId = $tenantId;
        // Outbound reuses the web's tracking number as the ticket (FD-3); if a pre-B3
        // two-arg leg carried none, mint one so every call still has a unique ticket.
        $this->ticketNumber = $correlationId ?? (string) Str::uuid();
        // The agent's leg is already up and committed to this call, so it enters the set
        // CONNECTED (no reservation — they picked themselves). It carries the dialing
        // agent's user id (B2.4a thread, may be null on a pre-B2.4a leg) so an outbound
        // call is transferable too; the switchboard finds this handler by it. The
        // switchboard already registered this leg when it made the handler.
        $this->agents[$agentLegId] = new AgentLeg($agentLegId, userId: $servingAgentId, connected: true);

        // CT-16: outbound gets the same carrier as inbound, so an outbound call has talk
        // time too. No arrival on it — nobody waited, we placed the call (CT-8 unchanged,
        // and still by construction rather than by a rule). The note's own created_at is
        // the moment we started ringing the CUSTOMER, which is written immediately below
        // — the outbound mirror of the inbound ring. Skipped when the leg carries no
        // agent id (a pre-B2.4a leg): there is nobody to file it under.
        if ($servingAgentId !== null) {
            $this->writeHandoffNote($servingAgentId, arrivedAt: null);
        }

        $this->callerLegId = $this->telephony->placeCall(
            config('telephony.outbound.dial_prefix').$customerNumber.config('telephony.outbound.dial_suffix'),
            'outbound',
            config('telephony.outbound.caller_id'),
        );
        $this->registry->registerLeg($this->callerLegId, $this);   // the leg we placed is ours (FD-2)
        $this->state = CallFlowState::RingingCustomer;

        Log::info('Outbound call: agent connected, ringing the customer.', [
            'ticket' => $this->ticketNumber,
            'tenant' => $tenantId,
            'agent' => $agentLegId,
            'customer' => $this->callerLegId,
            'customerNumber' => $customerNumber,
        ]);
    }

    /**
     * The progressive dialer placed a call (DIAL-1 A3 / DP-7). The mirror image of
     * beginOutboundCall: there, an agent is already on the line and we ring the customer;
     * here NOBODY is on our side yet — a desk is booked on the board and the customer's
     * phone is ringing into an empty room. The desk is only rung once they answer (A4).
     *
     * 🔴 THE HANDLER IS MADE BEFORE THE CALL IS PLACED, and that ordering is the whole
     * reason this method exists rather than the dialer calling placeCall() itself. An
     * unanswered call never enters our app — no StasisStart — so its ONLY event is a
     * ChannelDestroyed, and the switchboard drops legs it does not recognise. Registering
     * the leg here, the instant it is placed, is what makes that destruction reach a
     * handler that can hand the desk back. Without it a desk leaks on every unanswered
     * dial, which is most of them, and nothing in this system ever gives one back
     * (DQ-1: releaseReservation is called from a handler and there is no reaper).
     *
     * The booking rides on pendingReserved* — the slot S88 added for exactly this gap,
     * an agent tagged On a call before any leg exists to carry them. releaseAllReservations
     * already clears it, so every teardown path frees the desk with no new code.
     *
     * The lead is already claimed by the caller (DP-3) and stays claimed: its 90-second
     * claim IS the attempt delay, so nothing here releases it.
     */
    public function beginDialedCall(int $tenantId, int $agentUserId, Lead $lead, Campaign $campaign): void
    {
        $this->tenantId = $tenantId;
        $this->pendingReservedTenantId = $tenantId;
        $this->pendingReservedAgentId = $agentUserId;
        $this->dialedLeadId = $lead->id;
        $this->dialedCampaignId = $campaign->id;
        $this->correlationId = (string) Str::uuid();
        $this->ticketNumber = $this->correlationId;
        // The campaign's own number, because a marketing campaign must present a
        // 140-series number and a support one a 1601-series (DQ-5). Blank falls back to
        // the system number, which is what a manual campaign has always used.
        $ourNumber = $campaign->caller_id ?? config('telephony.outbound.caller_id');
        // 🔴 THE SAME WAY ROUND AS INBOUND (A4). callerNumber is the person at the other
        // end; dialledNumber is which of OUR numbers the call is on. That is the pairing
        // beginCall() sets and every reader downstream assumes, and here it IS the screen
        // pop: ringAgent() hands callerNumber to the agent's phone as caller-ID and the
        // console matches that number to a lead (lookupLead). The other way round, the
        // agent's phone shows our own number and their screen pops nothing.
        //
        // Normalized for the reason beginCall normalizes (CH-5) — one clean value for the
        // caller-ID, the handoff note and any row written later. The DIAL string keeps the
        // raw column value; only what we hand to readers is cleaned.
        $this->callerNumber = PhoneNumber::normalize($lead->phone);
        $this->dialledNumber = $ourNumber;
        // 🔴 `startedAt` is deliberately NOT stamped here (F16). It is the zero of the
        // wait clock, and a ringing phone is not a wait — nobody is on the line yet. The
        // stamp happens the moment they answer, in onArrival's `dialer` branch.
        $this->loadQueueSettings($tenantId);

        $this->callerLegId = $this->telephony->placeCall(
            config('telephony.outbound.dial_prefix').$lead->phone.config('telephony.outbound.dial_suffix'),
            'dialer',
            $ourNumber,
        );
        $this->registry->registerLeg($this->callerLegId, $this);
        $this->state = CallFlowState::DialingCustomer;

        Log::info('Progressive dial: ringing the customer, a desk is held for them.', [
            'ticket' => $this->ticketNumber,
            'tenant' => $tenantId,
            'campaign' => $campaign->id,
            'lead' => $lead->id,
            'agent' => $agentUserId,
            'customer' => $this->callerLegId,
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

        // The agent has PICKED UP — that is what this event means, and the call stops being
        // an unanswered one from this line onwards, before any verb that could be refused
        // (S88 review #5). Marked here rather than after the recording starts because the
        // missed-call safety net reads this: with the flip left until the end, a refused
        // join between the two wrote a "nobody answered" row for a call the agent had
        // physically answered — and their console, which claimed this call's ticket the
        // moment their phone rang, would stamp the same ticket on its own row at wrap-up.
        // Two rows, one call. Nothing between here and the old position reads the state.
        $this->state = CallFlowState::InCall;

        // The caller and the agent are about to be on one line, so the hold music stops
        // HERE rather than when the agent's phone started ringing (S88 review #3) — that
        // is what keeps the wait sounding like a wait instead of a dropped call.
        $this->stopHoldMusicIfPlaying();

        $this->conversationId = $this->telephony->join((string) $this->callerLegId, $agent->legId);
        $this->recording = $this->telephony->startRecording(
            (string) $this->callerLegId,
            'call-'.now()->format('Ymd-His'),
        );

        // Flip ringing -> connected and clear (not release) the reservation. The serving
        // agent's user id already rode in with the AgentLeg, so there is nothing to copy
        // here (the B2.4a "copy before the wipe" dance is gone — each member owns its id).
        $agent->markConnected();

        // The pickup moment (CT-2), onto this agent's own note. This is the line where
        // caller and agent are joined, so it is what "answered" means — and it is the
        // point the customer's wait stops, which is where every vendor stops it too.
        $this->stampHandoff(['answered_at' => now()], $agent->userId);

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
     * Park the caller mid-conversation (hold.md H-2): take their line out of the
     * conversation and start music on it. The agent's own line never moves, so the agent
     * can talk to a colleague, look something up, or take a breath.
     *
     * It is the waiting room again, entered from the middle of the call. A caller waits
     * on their own line with music playing on it — that is all the waiting room has ever
     * been — so the same two verbs serve both halves of the call and nothing new is
     * needed at the telephony layer.
     *
     * *Why the recording is undisturbed:* the two silent listeners sit on the CALLER's
     * line, and that line never moves. Taking it out of the conversation leaves them
     * exactly where they were, which is the same property the conference relies on. The
     * recording therefore contains the music for as long as the hold lasted (H-11), and
     * that is correct — the recording is a record of the call as it happened.
     *
     * Only from a connected call, and only one hold at a time: a second Hold while the
     * caller is already held is a no-op, so the total can never be started twice.
     */
    public function beginHold(int $agentUserId): void
    {
        if ($this->state !== CallFlowState::InCall || $this->heldSince !== null) {
            return;
        }

        $this->telephony->removeFromBridge((string) $this->conversationId, (string) $this->callerLegId);
        $this->startHoldMusicIfSilent();

        $this->heldSince = now();
        $this->heldByAgentId = $agentUserId;

        Log::info('Hold: the caller is parked with music on; the agent keeps their line.', [
            'ticket' => $this->ticketNumber,
            'caller' => $this->callerLegId,
            'byAgent' => $agentUserId,
        ]);
    }

    /**
     * Put the caller back into the conversation (hold.md H-2): stop the music, return
     * their line to the same conversation, and add the seconds they were held to this
     * call's total.
     *
     * Any agent on the call may resume — the button is a toggle on one screen, but a
     * three-way has two screens and stranding the caller because the wrong one clicked
     * would be the worst possible outcome. The TOTAL still belongs to whoever pressed
     * Hold (H-12); who ends the hold does not change whose decision it was.
     *
     * A no-op when nobody is held, exactly as a `transfer` signal naming an agent on no
     * call is a no-op.
     */
    public function resumeHold(): void
    {
        if ($this->heldSince === null) {
            return;
        }

        $this->closeHold();
        $this->stopHoldMusicIfPlaying();
        $this->telephony->addToBridge((string) $this->conversationId, (string) $this->callerLegId);

        Log::info('Hold: the caller is back in the conversation.', [
            'ticket' => $this->ticketNumber,
            'caller' => $this->callerLegId,
            'heldSecondsByAgent' => $this->heldSeconds,
        ]);
    }

    /**
     * Close an open hold and write the holding agent's own running total to their note
     * (hold.md H-8). Every ending closes the hold first, and there is exactly one place
     * to do that — dispose(), the funnel every teardown path reaches, which is where the
     * hang-up moment is already stamped for the same reason.
     *
     * The total is added up against the agent who pressed Hold, so an agent who holds
     * three times carries the sum of their three, and an agent on the same call who held
     * once carries only their one (S112 review #1).
     *
     * The total is written as an ABSOLUTE number every time it changes, so nothing has
     * to add up inside the database and a lost write costs one figure rather than
     * corrupting a running sum.
     *
     * A no-op when nobody is held, which is every ordinary call.
     */
    private function closeHold(): void
    {
        if ($this->heldSince === null) {
            return;
        }

        // Never null while a hold is open: beginHold() sets both, and the guard above
        // only lets us past when it did.
        $agentUserId = (int) $this->heldByAgentId;

        $this->heldSeconds[$agentUserId] = ($this->heldSeconds[$agentUserId] ?? 0)
            + (int) $this->heldSince->diffInSeconds(now());
        $this->heldSince = null;

        $this->stampHandoff(['hold_seconds' => $this->heldSeconds[$agentUserId]], $agentUserId);
    }

    /**
     * A supervisor asked to monitor this call (SM slices 2-4). Ring their own phone;
     * everything else waits until they pick up, because there is nothing to feed a tap
     * into before then.
     *
     * 🔴 THE CALL ITSELF DOES NOT MOVE. No state change, no new member in the
     * participant set, no touch of the conversation the caller and agent are in. That is
     * the whole reason this slice is small: a monitor is not a participant, so none of
     * the machinery that manages participants has to learn about them.
     *
     * Silence is the default rather than something we switch on: a tap that only spies
     * carries no audio back into the call, so the supervisor's microphone reaches nobody
     * however loudly they cough. $mode 'whisper' is slice 3 — the same tap, with audio
     * pushed into the AGENT's ear as well. Any other value is silent, so a mode this
     * method does not recognise can only ever under-share.
     *
     * $mode 'barge' is slice 4, and it is the one mode that is audible to the customer —
     * on purpose, and gated by its own permission on the board (SM-4). It still does not
     * make the supervisor a participant: see onMonitorAnswered.
     *
     * Four ways this quietly does nothing, all of them right:
     *  - the call is not in a state with a conversation to tap;
     *  - this supervisor is already monitoring (a double-click must not ring them twice,
     *    and pressing the other mode's button needs a Stop first — see MonitorLeg);
     *  - the named agent is not connected to this call (they hung up, or a transfer
     *    moved the call to somebody else between the board's 15-second refresh and the
     *    click);
     *  - the supervisor holds no phone (PP-12) — the button is hidden for them, but the
     *    signal is reachable from any browser, so it is refused here too.
     */
    public function beginMonitor(int $agentUserId, int $supervisorUserId, string $mode = 'listen'): void
    {
        if ($this->state !== CallFlowState::InCall && $this->state !== CallFlowState::AddingAgent) {
            return;
        }

        foreach ($this->monitors as $monitor) {
            if ($monitor->userId === $supervisorUserId) {
                return;
            }
        }

        $endpoint = $this->directory->endpointFor($supervisorUserId);

        if ($endpoint === null || $this->connectedLegOf($agentUserId) === null) {
            return;
        }

        // Registered the instant the id comes back, which is BEFORE the leg's own
        // arrival reaches us on the event pipe — the house rule every leg we place
        // follows, and what makes the pickup route to this handler rather than being
        // mistaken for a new call.
        $legId = $this->telephony->placeCall($endpoint, 'monitor');
        $this->monitors[$legId] = new MonitorLeg($legId, userId: $supervisorUserId, agentUserId: $agentUserId, mode: $mode);
        $this->registry->registerLeg($legId, $this);

        Log::info('Monitor: ringing the supervisor so they can hear this call.', [
            'ticket' => $this->ticketNumber,
            'mode' => $mode,
            'agentUser' => $agentUserId,
            'supervisorUser' => $supervisorUserId,
            'monitorLeg' => $legId,
        ]);
    }

    /**
     * The supervisor's phone picked up (SM slice 2). Now there is somewhere to send the
     * audio, so tap the agent's line and put the tap and the supervisor into a mixer of
     * their own — or, for a barge, put their line straight into the call.
     *
     * 🔴 TAPPED ON THE AGENT'S LINE, NOT THE CUSTOMER'S, and the reason is not
     * preference. Recording already holds two taps on the customer's line; the agent's
     * carries none, so this is the FIRST one there rather than a third one stacked on a
     * line that is already busy (SQ-6). `both` on the agent's line is the whole
     * conversation anyway — what the agent says, plus what the agent hears, which is the
     * customer.
     *
     * A mixer of its own, never the call's, so that ending a monitoring session folds
     * something no participant is standing in.
     *
     * The agent is looked up again here rather than trusted from the ring: a transfer
     * completing in those few seconds hands the call to somebody else entirely, and
     * listening to a line that no longer exists is worse than not listening at all.
     *
     * 🔴 SLICE 3, AND IT IS THIS ONE WORD. 'out' is the audio being written TO the
     * agent's line — what the agent hears — so the supervisor lands in the agent's ear
     * and nowhere else. 'in' would be the audio read FROM that line, which is what goes
     * on to the customer, and is therefore the leak this feature must never have.
     * Asterisk 20 maps the spy and whisper directions through the same translation
     * (res_stasis_snoop.c), which is why recording's own two taps use these same two
     * words for said-vs-heard.
     *
     * Anything other than the exact word 'whisper' taps silently, so an unrecognised
     * mode can only ever under-share.
     *
     * The tap is put in the switchboard's phone-book so that its DEATH is noticed. A
     * transfer completing under a live session, or the agent leaving a call that carries
     * on without them, hangs up the line this tap sits on. Without this the supervisor is
     * left holding a live phone wired to nothing — which merely sounds broken while
     * listening, but while coaching is worse: they talk, nobody hears them, and it reads
     * exactly like an agent ignoring them.
     */
    private function onMonitorAnswered(string $legId): void
    {
        $monitor = $this->monitors[$legId] ?? null;

        if ($monitor === null || $monitor->tapLegId !== null) {
            return;
        }

        $agentLegId = $this->connectedLegOf($monitor->agentUserId);

        if ($agentLegId === null) {
            Log::info('Monitor: the agent left this call while the supervisor was picking up — nothing to monitor.', [
                'ticket' => $this->ticketNumber,
                'supervisorUser' => $monitor->userId,
            ]);

            $this->stopMonitor($monitor);

            return;
        }

        // 🔴 SLICE 4, AND IT IS THIS BRANCH. Barge does not tap anything: the
        // supervisor's own line joins the call's conversation, which is the same verb
        // and the same bridge a conferenced third agent already uses. So all three
        // parties hear each other for free, and there is no second audio path to get
        // the direction of wrong.
        //
        // 🔴 NEITHER tapLegId NOR conversationId IS SET, and that is what makes stopping
        // safe. Teardown hangs up the tap it names and ends the conversation it names;
        // for a barge those fields would name the CALL's own bridge and the supervisor
        // leaving would take the customer's call down with them. Leaving both null means
        // stopping a barge is exactly "hang the supervisor's line up", which Asterisk
        // removes from the bridge on its own.
        //
        // The supervisor still never enters $agents, so no timing stamp, no handoff row
        // and no per-agent count can ever name them (SM-5). A barge is audible; it is
        // still not participation.
        //
        // Barging while the customer is parked on hold reaches the agent only, because
        // the held line is out of this conversation. That is the honest behaviour and
        // needs no special case.
        if ($monitor->mode === 'barge') {
            $this->telephony->addToBridge((string) $this->conversationId, $legId);

            Log::info('Monitor: the supervisor joined this call — everybody on it can hear them.', [
                'ticket' => $this->ticketNumber,
                'mode' => $monitor->mode,
                'agentUser' => $monitor->agentUserId,
                'supervisorUser' => $monitor->userId,
            ]);

            return;
        }

        $monitor->tapLegId = $this->telephony->snoop(
            $agentLegId,
            spy: 'both',
            whisper: $monitor->mode === 'whisper' ? 'out' : 'none',
        );
        $monitor->conversationId = $this->telephony->join($monitor->tapLegId, $legId);
        $this->registry->registerLeg($monitor->tapLegId, $this);

        Log::info($monitor->mode === 'whisper'
            ? 'Monitor: the supervisor is coaching this agent; the customer cannot hear them.'
            : 'Monitor: the supervisor is hearing the call; neither the agent nor the customer hears them.', [
                'ticket' => $this->ticketNumber,
                'mode' => $monitor->mode,
                'agentUser' => $monitor->agentUserId,
                'supervisorUser' => $monitor->userId,
                'tap' => $monitor->tapLegId,
            ]);
    }

    /**
     * End one monitoring session and leave the call exactly as it was: release the tap,
     * put the supervisor's phone down, fold their mixer away.
     *
     * 🔴 A barge has neither a tap nor a mixer of its own, so this narrows to one line —
     * hang the supervisor's phone up — and the switch takes their line out of the call's
     * bridge. Every other party is untouched, which is slice 4's "the supervisor leaving
     * does not end the call".
     *
     * Every step is best-effort, because the usual way this runs is that something has
     * already gone: the supervisor hung up, or the call ended underneath them, and half
     * of these legs are dead before we ask. A refusal on one must not skip the next.
     *
     * The leg stays in the switchboard's phone-book, which is harmless — it points at a
     * live handler that no longer knows the leg, so its events are ignored, and the
     * handler's own teardown clears every entry at once.
     */
    private function stopMonitor(MonitorLeg $monitor): void
    {
        unset($this->monitors[$monitor->legId]);

        if ($monitor->tapLegId !== null) {
            rescue(fn () => $this->telephony->hangup((string) $monitor->tapLegId), report: false);
        }

        rescue(fn () => $this->telephony->hangup($monitor->legId), report: false);

        if ($monitor->conversationId !== null) {
            rescue(fn () => $this->telephony->endConversation((string) $monitor->conversationId), report: false);
        }
    }

    /**
     * End every monitoring session on this call — the call is going away under them.
     * Called from the two teardown doors (endCall, hangupHeldLegs), which between them
     * cover every way a call ends: the caller hanging up, the last agent leaving, a
     * refused verb, and the switchboard's bug backstop.
     *
     * 🔴 Without this a supervisor is left holding a live phone connected to a tap on a
     * line that no longer exists — silence they have no way to end except by hanging up,
     * and a tap channel and a bridge left on the switch for good.
     */
    private function stopAllMonitors(): void
    {
        foreach ($this->monitors as $monitor) {
            $this->stopMonitor($monitor);
        }
    }

    /** Which leg is this agent talking on right now, if they are on this call at all. */
    private function connectedLegOf(int $agentUserId): ?string
    {
        foreach ($this->agents as $agent) {
            if ($agent->connected && $agent->userId === $agentUserId) {
                return $agent->legId;
            }
        }

        return null;
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

        // 🔴 Refused while the caller is held (hold.md H-9). Ringing a second agent needs
        // the caller to be IN the conversation, because the whole promise of both
        // features is that the caller is never left alone — and a held caller is already
        // out of it. Resuming for the agent is worse than refusing: it puts the caller
        // back into a conversation they were deliberately taken out of, at a moment the
        // agent did not choose. Press Resume, then Transfer. The two buttons are greyed
        // out on the screen while held, so the agent is told rather than ignored.
        if ($this->heldSince !== null) {
            Log::info('Add agent: refused while the caller is on hold — resume first.', [
                'ticket' => $this->ticketNumber,
                'intent' => $intent->name,
            ]);

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

        // B is booked on the board but nothing carries that booking until their leg record
        // exists a few lines down, so hold it here first (S88 review #1, the second half —
        // the inbound ring had this gap closed and this path did not). A refused placeCall
        // in between would otherwise leave B tagged On a call for good, with no leg for the
        // release to iterate — the identical bug, reached by clicking Transfer instead.
        $this->pendingReservedTenantId = $tenantId;
        $this->pendingReservedAgentId = $reservedAgentId;

        // 🔴 B gets a FRESH note before their phone rings (CT-5). Without it their screen
        // reads whatever note is left from their PREVIOUS call — a stale ticket today, on
        // this branch, independent of this feature; stale timing once the note carries
        // moments. No arrival on it: when this call reached B the customer was not in the
        // waiting room, so the wait belongs to the first agent alone.
        $this->writeHandoffNote($reservedAgentId, arrivedAt: null);

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
        $this->pendingReservedTenantId = null;         // B's leg carries the reservation now
        $this->pendingReservedAgentId = null;
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

            // 🔴 A's conversation ends HERE, not when the caller hangs up minutes later
            // (CT-12). The teardown stamp never reaches A — she is already dropped and
            // wrapped up by then — so without this line no transferred call would ever
            // carry talk time, which is the one thing transfers are budgeted for.
            $this->stampHandoff(['ended_at' => now()], $a->userId);
        }

        $b->markConnected();
        // B's pickup does NOT travel connectAgent() (CT-12) — this is the only place a
        // transferred-to agent is joined, so it is the only place their pickup exists.
        $this->stampHandoff(['answered_at' => now()], $b->userId);

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
        // Same as a transfer: B's pickup does not travel connectAgent() (CT-12). A is
        // untouched — they are still talking, so their note stays open and the teardown
        // closes both of them together.
        $this->stampHandoff(['answered_at' => now()], $b->userId);

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
     * alone -> end). A monitoring tap dying ends that supervisor's session only.
     * Recording's snoops and a freshly-dropped survivor match no tracked id and fall
     * through untouched.
     *
     * @param  array<string, mixed>  $event
     */
    /**
     * The closed message finished playing (inbound-audio slice 4): end the call. The
     * caller was never going to reach anyone — this is the whole of what the client
     * chose — and their Missed Calls row was written at the door, so there is nothing
     * left to do but hang up.
     *
     * Only OUR play ends the call. The engine says which play finished, not why, so an
     * unmatched id is another slice's sound and is left alone.
     *
     * @param  array<string, mixed>  $event
     */
    private function onPlaybackFinished(array $event): void
    {
        if ($this->state !== CallFlowState::PlayingClosedMessage) {
            return;
        }

        if (($event['playback']['id'] ?? null) !== $this->closedMessagePlaybackId) {
            return;
        }

        Log::info('Inbound call: the closed message finished — ending the call.', [
            'ticket' => $this->ticketNumber,
            'tenant' => $this->tenantId,
            'caller' => $this->callerLegId,
        ]);

        if ($this->callerLegId !== null) {
            rescue(fn () => $this->telephony->hangup($this->callerLegId), report: false);
        }

        $this->dispose();
    }

    private function onLegEnded(array $event): void
    {
        $legId = $event['channel']['id'] ?? '';

        // The caller hung up on the closed message, or the hang-up we asked for above
        // came back to us (inbound-audio slice 4). Either way the call is over and the
        // Missed Calls row is already written — the door wrote it before a single sound
        // played, precisely so this path never has to.
        if ($this->state === CallFlowState::PlayingClosedMessage) {
            if ($legId === $this->callerLegId) {
                $this->dispose();
            }

            return;
        }

        if ($this->state === CallFlowState::Waiting) {
            if ($legId === $this->callerLegId) {
                Log::info('The customer waiting on hold gave up — writing them to the missed-call list.', [
                    'ticket' => $this->ticketNumber,
                    'tenant' => $this->tenantId,
                    'wasDialled' => $this->dialedLeadId !== null,
                    'waitedSeconds' => (int) $this->startedAt?->diffInSeconds(now()),
                ]);

                $this->recordMissedCall(CallOutcome::Abandoned);
                $this->dispose();
            }

            return;
        }

        if ($this->state === CallFlowState::DialingCustomer) {
            // Nobody answered a call the dialer placed (DP-9). Hand the desk straight back
            // and write the dial down. recordMissedCall() counts the attempt as well, so the
            // number goes to the back of its tier rather than being dialled again on the
            // very next tick — which is why this branch no longer counts one of its own.
            //
            // 🔴 An outbound `no_answer` row with no agent (F21): DP-12a divides by every
            // dial placed, and a ring-out used to leave nothing to count. But NEVER
            // CallOutcome::Abandoned. Abandoned means a person was on the line and no agent
            // reached them; this is a phone that rang out. That column is the top of the 3%
            // cap, and filling it with no-answers would put a compliant floor over the line
            // on paper.
            if ($legId === $this->callerLegId) {
                // 🔴 Measurement only, S155 — nothing reads this and nothing should.
                // Asterisk puts its own hangup reason on ChannelDestroyed (Q.850: 17 is
                // busy, 19 rang out); StasisEnd carries none, and whichever of the two
                // lands first tears the call down, so the reason is here SOMETIMES. That
                // is precisely the number we need: a week of these says how often the
                // engine actually tells us, which decides whether per-reason retry gaps
                // are worth building at all. Asterisk has open bugs (#963, #1660) on the
                // cause going missing for exactly the dial-timeout case, so do not build
                // on this field before the log says it is there.
                // ponytail: a measurement with an end date, not a permanent log line. It
                // writes on every unanswered dial, so delete it once a week of staging has
                // answered how often `cause` is actually present — or promote it to a
                // counted field if the answer is "always" and per-reason gaps get built.
                Log::info('A dialled call ended with nobody on it.', [
                    'ticket' => $this->ticketNumber,
                    'tenant' => $this->tenantId,
                    'eventType' => $event['type'] ?? null,
                    'cause' => $event['cause'] ?? null,
                    'causeText' => $event['cause_txt'] ?? null,
                ]);

                $this->releaseAllReservations();
                $this->recordMissedCall(CallOutcome::NoAnswer);
                $this->dispose();
            }

            return;
        }

        if ($this->state === CallFlowState::RingingAgent || $this->state === CallFlowState::RingingCustomer) {
            // Fold B: a reserved agent never connected — release on BOTH no-answer paths
            // (the agent rang out, or the caller abandoned mid-ring). A no-op for outbound.
            $this->releaseAllReservations();

            if (isset($this->agents[$legId])) {
                $rungOutAgentId = $this->agents[$legId]->userId;
                unset($this->agents[$legId]);

                // QD-4's second door: an inbound agent let their phone ring out. The
                // caller goes back to the waiting room rather than being cut off, and
                // that desk is noted as of NOW, which keeps it out of the next attempt
                // for one ring and then lets it back in (S88 — the list cools off).
                if ($this->state === CallFlowState::RingingAgent && $this->callerLegId !== null) {
                    if ($rungOutAgentId !== null) {
                        $this->rangOutAt[$rungOutAgentId] = now();
                    }

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
                // CE-11: the customer put the phone down. Stamped HERE rather than left
                // to dispose()'s teardown stamp, because by then the branch that knows
                // which side went is behind us — dispose() closes whoever is still open
                // and cannot say why. First close wins (CT-12a), so this reaches every
                // agent still on the call and no agent who already left.
                $this->stampHandoff(['ended_at' => now(), 'ended_by' => CallEndedBy::Customer]);
                $this->endCall($legId);

                return;
            }

            $monitor = $this->monitors[$legId] ?? null;

            if ($monitor !== null) {
                // The supervisor put their phone down (SM slice 2) — the Stop button and
                // a closed tab both arrive here, because both simply hang the leg up. The
                // call carries on completely untouched.
                //
                // 🔴 THIS BRANCH IS WHAT KEEPS A BARGE FROM ENDING THE CALL. A barging
                // supervisor's line is inside the call's own bridge, so without being
                // caught here first it would fall through to the participant rules below
                // — where the last connected agent leaving ends the call. It is caught
                // here because monitors are looked up before agents, and a monitor is
                // never in $agents to be found by them.
                Log::info('Monitor: the supervisor stopped; the call is unaffected.', [
                    'ticket' => $this->ticketNumber,
                    'mode' => $monitor->mode,
                    'supervisorUser' => $monitor->userId,
                ]);

                $this->stopMonitor($monitor);

                return;
            }

            // 🔴 THE TAP DIED UNDER A LIVE SESSION (SM slice 3). Asterisk ends a tap when
            // the line it sits on goes away, and that line goes away on two ordinary
            // events this system already has: a cold transfer swapping the agent leg, and
            // an agent leaving a call that carries on with somebody else. The call is
            // fine; the supervisor's half is not. End their session so their phone hangs
            // up, which tells them plainly, rather than leaving them talking into a line
            // that stopped existing.
            foreach ($this->monitors as $monitor) {
                if ($monitor->tapLegId === $legId) {
                    Log::info('Monitor: the agent line this session was tapping has gone — ending the supervisor\'s session.', [
                        'ticket' => $this->ticketNumber,
                        'mode' => $monitor->mode,
                        'agentUser' => $monitor->agentUserId,
                        'supervisorUser' => $monitor->userId,
                    ]);

                    $this->stopMonitor($monitor);

                    return;
                }
            }

            $agent = $this->agents[$legId] ?? null;

            if ($agent === null) {
                return;   // not a leg we track (a recording snoop, a freshly-dropped survivor)
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

            // Their part of the conversation ended here (CT-12a). On a conference the
            // others carry on for as long as they like, and the teardown stamp will not
            // reach this agent because first close wins — so without this line, an agent
            // who leaves a three-way early carries the whole call's length as talk time.
            // The side that hung up rides along on the same stamp (CE-11).
            $this->stampHandoff(['ended_at' => now(), 'ended_by' => CallEndedBy::Agent], $agent->userId);

            if ($this->connectedAgents() !== []) {
                // 🔴 The agent who held the caller has gone, and somebody else is still on
                // the call — so put the caller back rather than leave them listening to
                // music nobody can stop. Not in hold.md, which answers what happens when
                // the call ENDS while held (H-8) and does not reach this one: the call
                // does not end here, it carries on without the person who parked the
                // caller. The never-strand rule decides it. The remaining agent's own
                // button reads "Hold", so without this the caller is stuck until they
                // hang up.
                if ($this->heldByAgentId === $agent->userId) {
                    $this->resumeHold();
                }

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
        // SM slice 2: the listeners go before anything else, while their tap's target is
        // still up — the call is ending under them and nobody else will release them.
        $this->stopAllMonitors();

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
        $this->recordMissedCallIfNeverConnected();
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
        // hold.md H-8: any path that ends this agent's part closes the hold first. The
        // caller hanging up while held, the agent hanging up while held, and a call torn
        // down by an error all funnel through here, so one line answers all three. Before
        // the stamp below, because the two write to different columns and the hold total
        // must reach a note that the hang-up stamp is about to close.
        $this->closeHold();

        // The hang-up (CT-6), onto every note on this call that has not already closed
        // — first close wins (CT-12a). Every teardown path funnels through here, so a
        // call that ends any way at all closes whoever was still talking. Deliberately
        // NOT in discard(), the switchboard's bug-backstop: a crashed call leaving no
        // end time is CT-6's honest blank, not a number worth inventing. Ordered before
        // reset(), which wipes the ticket this needs.
        $this->stampHandoff(['ended_at' => now()]);

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
        $this->recordMissedCallIfNeverConnected();
        $this->hangupHeldLegs();
        $this->reset();
    }

    /**
     * An error tore this call down — a refused verb (abort) or the switchboard's backstop
     * catching a bug (discard). If nobody had reached an agent yet, the caller still gets
     * the missed-call row QD-5 promises (S88 review #5): without this, the one outcome the
     * waiting room exists to guarantee is the one that silently does not happen, and the
     * caller vanishes from every call report exactly as they would have before this slice.
     *
     * `no_answer` per QD-6's mapping — we stopped waiting on their behalf, they did not
     * give up — except on a dialled call, which recordMissedCall() files `abandoned`
     * (F22). Only for a call that never connected: once an agent is on the line their
     * screen owns the row (D2), and writing here would duplicate it. Ordered BEFORE the
     * teardown because reset() wipes the client, the ticket and the arrival time this
     * needs. The write is best-effort inside recordMissedCall(), so an error path cannot
     * become a second error.
     *
     * DialingCustomer is here as the NET UNDER ringAgent's own catch, not as a second way
     * to write F28's row — that row is now the waiting room's, and a dial that reaches the
     * catch never reaches here at all. What is left is a teardown that strikes while the
     * dial is still in flight and before the ring is placed: startHoldMusicIfSilent() is a
     * raw verb with no rescue of its own, and discard() arrives here from the switchboard's
     * backstop on any unexpected error. Which row that writes is recordMissedCall's usual
     * question and it answers it correctly either way — a refused verb AFTER the customer
     * said hello has `startedAt` stamped and is filed `abandoned` (F22), while a teardown
     * on a number still ringing has none and is filed as the ring-out it is (F21). Both
     * count the attempt. No double write: onLegEnded's own DialingCustomer branch disposes,
     * and reset() puts the state back to Idle behind it.
     *
     * ponytail: the same early-teardown hole is still open on the INBOUND first ring —
     * beginCall assigns no state at all, so an answered caller lost to a refused hold-music
     * verb writes nothing. Not reachable through placeCall any more (ringAgent catches it)
     * and never measured, so it is left alone; adding Idle to this guard closes it if it
     * ever shows up in a log.
     */
    private function recordMissedCallIfNeverConnected(): void
    {
        if ($this->state !== CallFlowState::Waiting
            && $this->state !== CallFlowState::RingingAgent
            && $this->state !== CallFlowState::DialingCustomer) {
            return;
        }

        $this->recordMissedCall(CallOutcome::NoAnswer);
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

    /**
     * Best-effort hang up every leg we still hold (caller + all agent legs), and release
     * any supervisor listening in (SM slice 2) — this is the error door, abort() and the
     * switchboard's backstop, and a listener left behind by a crashed call is the same
     * orphan as one left behind by a normal ending.
     */
    private function hangupHeldLegs(): void
    {
        $this->stopAllMonitors();

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

    /**
     * Release every reservation we still hold — a ringing inbound/added agent, and the one
     * booked but not yet carried by a leg (S88 review #1). The pending one is released
     * first, and by the company/agent pair it was booked with rather than the call's own,
     * because a transfer books on an outbound call whose tenantId is null. The release
     * itself is the router's usual conditional one, so it still cannot overwrite a status
     * the agent's screen set during the gap.
     */
    private function releaseAllReservations(): void
    {
        if ($this->pendingReservedTenantId !== null && $this->pendingReservedAgentId !== null) {
            $this->router->releaseReservation($this->pendingReservedTenantId, $this->pendingReservedAgentId);
            $this->pendingReservedTenantId = null;
            $this->pendingReservedAgentId = null;
        }

        foreach ($this->agents as $agent) {
            $this->releaseReservation($agent);
        }
    }

    /**
     * Count one attempt against the lead this call was dialled from (DP-9). Straight to
     * the database rather than through the model: nothing else on the row is being
     * touched, and `attempts` is read by Lead::callable's ordering and by the campaign's
     * give-up cap, so it must move by exactly one even if two ticks race.
     *
     * The claim is deliberately left alone — its 90 seconds ARE the attempt delay.
     */
    private function countDialAttempt(): void
    {
        if ($this->dialedLeadId === null || $this->tenantId === null) {
            return;
        }

        $leadId = $this->dialedLeadId;

        TenantContext::run(
            $this->tenantId,
            fn () => Lead::query()->whereKey($leadId)->increment('attempts'),
        );
    }

    /** Wipe this call's state. Part of disposal — a handler is one-per-call now (B2.1). */
    private function reset(): void
    {
        $this->state = CallFlowState::Idle;
        $this->callerLegId = null;
        $this->dialedLeadId = null;
        $this->dialedCampaignId = null;
        $this->agents = [];
        $this->monitors = [];
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
        $this->holdMusicClass = null;
        $this->closedMessagePlaybackId = null;
        $this->rangOutAt = [];
        $this->pendingReservedTenantId = null;
        $this->pendingReservedAgentId = null;
        $this->holdMusicOn = false;
        $this->heldSince = null;
        $this->heldSeconds = [];
        $this->heldByAgentId = null;
    }
}
