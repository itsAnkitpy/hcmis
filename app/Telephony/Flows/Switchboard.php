<?php

declare(strict_types=1);

namespace App\Telephony\Flows;

use App\Jobs\MergeCallRecordingJob;
use App\Telephony\AriConnectionLost;
use App\Telephony\RecordingSession;
use App\Telephony\TelephonyProvider;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The B2.1 foundation: the one receptionist who can hold MANY calls at once.
 *
 * Before B2.1 the listener built ONE CallToAgentFlow and fed it every event, so a
 * second caller arriving mid-call matched no branch and was silently lost. The
 * switchboard replaces that single handler with a thin traffic-director: it keeps
 * a phone-book of "which leg belongs to which handler", makes a fresh handler when
 * a new call arrives, and routes every event to the right handler. Each call drives
 * itself in its own CallToAgentFlow, kept apart from its siblings (FD-1).
 *
 * It owns two pieces of shared memory the per-call handlers cannot:
 *  - the leg -> handler phone-book (the routing index, FD-2);
 *  - the "recordings still being stitched" notebook (pendingMerges, FD-4) — moved
 *    off the handler because a recording can finish a moment AFTER its call hangs
 *    up, and the handler is thrown away at hang-up now.
 *
 * Failure isolation (FD-6) is two nets: the handler tidies its own call on a known
 * telephony error (its existing abort), and the switchboard wraps every hand-off in
 * a backstop so an UNEXPECTED error tears down only that one call and keeps serving
 * everyone else. The one error that must still bubble all the way up is "the line to
 * the engine dropped" (AriConnectionLost) — that is everyone's problem and drives
 * the listener's reconnect, so the backstop re-throws it untouched.
 *
 * Still ONE listener program after B2.1 (FD-8): it works through events one step at
 * a time. The scale-out trigger (more listeners + shared state, CC-2) is written
 * down, not built.
 */
class Switchboard implements HandlerRegistry
{
    /**
     * The phone-book: leg id -> the handler that owns it. A handler always holds at
     * least one leg while alive, so this map is also the set of live calls.
     *
     * @var array<string, CallToAgentFlow>
     */
    private array $handlers = [];

    /**
     * Recordings whose merge is waiting on the engine to confirm both files
     * finished — moved here from the handler (FD-4) so a late RecordingFinished
     * survives the disposal of the call's handler. Keyed by recording name.
     *
     * @var array<string, array{callId: string, recording: RecordingSession, finished: array<string, true>}>
     */
    private array $pendingMerges = [];

    public function __construct(private readonly TelephonyProvider $telephony) {}

    /**
     * Feed the switchboard one raw engine event. RecordingFinished goes to the
     * shared notebook; a leg-entering event (StasisStart) is classified new-vs-known;
     * a ChannelUserevent is the web's control signal (B2.4a TD-4), routed by which
     * agent it names; every other per-leg event is routed to the leg's handler, or
     * harmlessly dropped if no handler owns it (exactly today's fall-through).
     *
     * @param  array<string, mixed>  $event
     */
    public function handle(array $event): void
    {
        switch ($event['type'] ?? '') {
            case 'RecordingFinished':
                $this->onRecordingFinished($event);

                return;
            case 'StasisStart':
                $this->onArrival($event);

                return;
            case 'ChannelUserevent':
                $this->onUserEvent($event);

                return;
            case 'PlaybackFinished':
                $this->onPlaybackFinished($event);

                return;
            default:
                $legId = $event['channel']['id'] ?? '';

                if (isset($this->handlers[$legId])) {
                    $this->dispatch($this->handlers[$legId], $event);
                }
        }
    }

    /**
     * A leg entered our app. Tell new calls from existing ones:
     *  - ['snoop']                              -> our recording taps; infrastructure, ignored.
     *  - a leg already in the phone-book        -> route to its handler (an inbound agent
     *                                              pickup ['agent'] or an outbound customer
     *                                              ['outbound'] — both legs the handler placed
     *                                              and registered, so they are already known).
     *  - []  (untagged outside caller)          -> a NEW inbound call: make a handler.
     *  - ['agent', <number>, <uuid?>]           -> a NEW outbound call (the agent-first leg):
     *                                              make a handler.
     *  - ['dialer'] / ['agent'] / ['outbound'] / ['monitor'] with no known leg
     *                                           -> a leg WE placed whose handler is gone
     *                                              (a restart mid-call): hung up, R8.
     *  - anything else with no known leg        -> dropped (not ours to end).
     *
     * Handlers register the legs they place BEFORE those legs' StasisStart arrives, so
     * the only un-registered arrivals are genuinely new calls.
     *
     * @param  array<string, mixed>  $event
     */
    private function onArrival(array $event): void
    {
        $args = $event['args'] ?? null;
        $legId = $event['channel']['id'] ?? '';

        if ($args === ['snoop']) {
            return;
        }

        if (isset($this->handlers[$legId])) {
            $this->dispatch($this->handlers[$legId], $event);

            return;
        }

        $isNewInbound = $args === [];
        $isNewOutbound = is_array($args) && count($args) >= 2 && $args[0] === 'agent';

        if ($isNewInbound || $isNewOutbound) {
            $handler = new CallToAgentFlow($this->telephony, $this);
            $this->registerLeg($legId, $handler);
            $this->dispatch($handler, $event);

            return;
        }

        // 🔴 A LEG WE PLACED, WHOSE HANDLER WE HAVE LOST (DIAL-1 R8). Handlers register
        // every leg they place BEFORE its arrival, so one of our own tags reaching here
        // unregistered means the handler that placed it is gone — a listener restart
        // mid-call, which Asterisk survives without hanging anything up (it deactivates
        // the application and reactivates it on reconnect).
        //
        // Dropping it, which is what used to happen, strands whoever is on that line in
        // silence: no music, no agent, no hangup, until they give up. The dialer makes one
        // of these per campaign per second, but it was never dialer-only — an inbound
        // ring's `agent` leg and the console's `outbound` leg orphan exactly the same way.
        //
        // Ending it is the honest outcome. Nothing in this process can serve that call:
        // its client, its lead, its booked desk and its ticket all died with the handler.
        // The desk is put back by the reservation reaper within the minute.
        if (in_array($args, [['dialer'], ['agent'], ['outbound'], ['monitor']], true)) {
            Log::warning('A leg we placed arrived with no handler to receive it — ending it rather than leaving the line silent.', [
                'leg' => $legId,
                'tag' => $args[0],
            ]);

            rescue(fn () => $this->telephony->hangup($legId), report: false);
        }
    }

    /**
     * Route one raw event to one handler behind the failure backstop (FD-6).
     *
     * @param  array<string, mixed>  $event
     */
    private function dispatch(CallToAgentFlow $handler, array $event): void
    {
        $this->guard($handler, fn () => $handler->handle($event));
    }

    /**
     * Run one action against one handler behind the failure backstop (FD-6). A known
     * telephony error is already handled inside the handler (it tidies its own call);
     * this catches the UNEXPECTED — a bug — and tears down only that one call, logging
     * it, so its siblings keep running. "The line dropped" (AriConnectionLost) is
     * re-thrown untouched: it is everyone's problem and drives the reconnect. Shared by
     * event routing (dispatch) and the web-signal actions (onUserEvent), so a transfer
     * that blows up takes down only its own call.
     *
     * @param  callable(): void  $action
     */
    private function guard(CallToAgentFlow $handler, callable $action): void
    {
        try {
            $action();
        } catch (AriConnectionLost $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('Switchboard backstop: tearing down one call after an unexpected error; the rest keep running.', [
                'error' => $exception->getMessage(),
            ]);

            $handler->discard();
            $this->release($handler);
        }
    }

    /**
     * The web's control signal arrived on the event pipe (B2.4a TD-4): no database
     * table, no poll — the listener hears it on the websocket it already holds. The
     * signal names which AGENT it is about (their server-derived user id) + the
     * company (tenant id), both carried in the user-event's variables. We find that
     * agent's live call and act on it; the signal NAME (eventname) is what we route
     * on, so this stays general for TD-7's supervisor-monitor, not transfer-only.
     *
     * @param  array<string, mixed>  $event
     */
    private function onUserEvent(array $event): void
    {
        $variables = $event['userevent'] ?? [];
        $agentUserId = isset($variables['agentUserId']) ? (int) $variables['agentUserId'] : null;
        $tenantId = isset($variables['tenantId']) ? (int) $variables['tenantId'] : null;
        // SM slice 2: WHO is listening, as well as who is being listened to. Derived from
        // web auth on the page that sent it, never read off the browser.
        $supervisorUserId = isset($variables['supervisorUserId']) ? (int) $variables['supervisorUserId'] : null;

        if ($agentUserId === null) {
            return;
        }

        $handler = $this->handlerServingAgent($agentUserId);

        if ($handler === null) {
            return;   // no live call for that agent (already ended, or never connected)
        }

        match ($event['eventname'] ?? '') {
            'transfer' => $tenantId === null
                ? null
                : $this->guard($handler, fn () => $handler->beginTransfer($tenantId)),
            'conference' => $tenantId === null
                ? null
                : $this->guard($handler, fn () => $handler->beginConference($tenantId)),
            // hold.md H-10: the same pipe, two more names. Neither needs the company —
            // nothing is reserved, so there is no board to read, and requiring it would
            // wrongly refuse a hold to our own global staff on an outbound call.
            'hold' => $this->guard($handler, fn () => $handler->beginHold($agentUserId)),
            'resume' => $this->guard($handler, fn () => $handler->resumeHold()),
            // SM slices 2-4: a supervisor monitors. No company needed, for the same reason
            // hold needs none — nothing is reserved, so there is no board to read. The
            // supervisor is named separately from the agent because they are two different
            // people, which is the whole shape of this signal.
            //
            // 🔴 TWO NAMES, NOT ONE NAME PLUS A MODE ON THE WIRE. The signal name is the
            // mode, so a browser can no more ask for coaching it was not offered than it
            // can invent a verb — an unknown name already falls to the harmless default
            // below. That is why there is no list of allowed modes anywhere in this path.
            'listen' => $supervisorUserId === null
                ? null
                : $this->guard($handler, fn () => $handler->beginMonitor($agentUserId, $supervisorUserId)),
            'whisper' => $supervisorUserId === null
                ? null
                : $this->guard($handler, fn () => $handler->beginMonitor($agentUserId, $supervisorUserId, 'whisper')),
            // SM slice 4. A third name on the same pipe, for the same reason: the name IS
            // the mode. The extra right barge needs (SM-4) is enforced where the button
            // is pressed, not here — this program has no web session to ask about roles,
            // which is exactly why every other permission in the system lives on that
            // side too.
            'barge' => $supervisorUserId === null
                ? null
                : $this->guard($handler, fn () => $handler->beginMonitor($agentUserId, $supervisorUserId, 'barge')),
            default => null,   // an unknown signal is harmlessly ignored
        };
    }

    /**
     * Which live handler is serving the given agent (B2.4a TD-4)? A scan of the live
     * handlers — chosen over a second index (fewer moving parts to keep in sync at our
     * volume, FD-8) — and the SAME general lookup TD-7's supervisor-monitor will reuse.
     * Each handler appears once per leg it holds, so dedupe by object as we go.
     */
    private function handlerServingAgent(int $agentUserId): ?CallToAgentFlow
    {
        foreach ($this->handlers as $handler) {
            if ($handler->isServingAgent($agentUserId)) {
                return $handler;
            }
        }

        return null;
    }

    /**
     * A sound finished playing (inbound-audio slice 4, shared with slices 5 and 6).
     *
     * 🔴 THIS EVENT NEEDS ITS OWN CASE FOR THE SAME REASON RecordingFinished DOES: it
     * carries NO top-level `channel`. Everything the default branch routes is keyed on
     * `channel.id`, so until now this event matched no handler and was silently dropped —
     * which is why nothing could ever act on a sound ending.
     *
     * The line is inside the playback object instead, as `target_uri` = `channel:<id>`
     * (verified against the 20 branch's own API spec: Playback.target_uri is "URI for the
     * channel or bridge to play the media on"). A bridge-targeted play would read
     * `bridge:<id>` and match no handler, which is the right outcome — nothing plays to a
     * bridge on our paths.
     *
     * Unlike the recording notebook, this stays plain routing: the handler that started
     * the play is the handler that owns the leg, and it is still alive, because the play
     * is happening on a call it is running.
     *
     * @param  array<string, mixed>  $event
     */
    private function onPlaybackFinished(array $event): void
    {
        $targetUri = $event['playback']['target_uri'] ?? '';

        if (! is_string($targetUri) || ! str_starts_with($targetUri, 'channel:')) {
            return;
        }

        $legId = substr($targetUri, strlen('channel:'));

        if (isset($this->handlers[$legId])) {
            $this->dispatch($this->handlers[$legId], $event);
        }
    }

    /**
     * One recording file finished. Tick its entry in the shared notebook; queue the
     * stereo merge only once both sides are confirmed (events are facts; a 2xx on the
     * stop request is not). Lives on the switchboard so a recording that confirms
     * AFTER its call's handler is disposed still merges (FD-4).
     *
     * @param  array<string, mixed>  $event
     */
    private function onRecordingFinished(array $event): void
    {
        $name = $event['recording']['name'] ?? '';

        foreach (array_keys($this->pendingMerges) as $key) {
            $session = $this->pendingMerges[$key]['recording'];

            if ($name !== $session->saidRecordingName() && $name !== $session->heardRecordingName()) {
                continue;
            }

            $this->pendingMerges[$key]['finished'][$name] = true;
            $finished = $this->pendingMerges[$key]['finished'];

            if (isset($finished[$session->saidRecordingName()], $finished[$session->heardRecordingName()])) {
                MergeCallRecordingJob::dispatch($this->pendingMerges[$key]['callId'], $session->name);
                unset($this->pendingMerges[$key]);
            }

            return;
        }
    }

    public function registerLeg(string $legId, CallToAgentFlow $handler): void
    {
        $this->handlers[$legId] = $handler;
    }

    public function release(CallToAgentFlow $handler): void
    {
        foreach (array_keys($this->handlers) as $legId) {
            if ($this->handlers[$legId] === $handler) {
                unset($this->handlers[$legId]);
            }
        }
    }

    public function depositMerge(string $callId, RecordingSession $recording): void
    {
        $this->pendingMerges[$recording->name] = [
            'callId' => $callId,
            'recording' => $recording,
            'finished' => [],
        ];
    }

    /**
     * Glance at the waiting room and pair whoever has waited longest with whichever desk
     * has freed up (B2.3b-i QD-3). Called by the listener on every pass of its loop, so
     * nothing new runs: no scheduler, no queue worker, no second process.
     *
     * The waiting LINE needs no data structure. Every live call already sits in the
     * phone-book, and a new inbound call registers its CALLER leg first, so PHP's own
     * insertion order IS arrival order — walking it hands the longest-waiting caller the
     * first freed desk for free. Each handler decides for itself whether it is waiting;
     * for every other call on the switch tryAgain() is a no-op.
     *
     * Behind the same per-call backstop as everything else (FD-6): a sweep that blows up
     * on one waiting call must not stop the others being swept.
     */
    public function sweepWaiting(): void
    {
        foreach ($this->uniqueHandlers() as $handler) {
            $this->guard($handler, fn () => $handler->tryAgain());
        }
    }

    /**
     * The three live call numbers, per client (Call Stats CS-1): how many calls are
     * connected, how many are ringing, and how many callers are holding. Nothing is
     * measured or invented — every live call already carries the state that answers this,
     * and the phone-book already holds every live call. Walks the SAME deduped list the
     * waiting-room sweep does, so a call is counted exactly once however many legs it has.
     *
     * A call mid-transfer or in a 3-way (AddingAgent) counts as ACTIVE on purpose: that
     * caller is talking to somebody. Counting it otherwise would make the number dip every
     * time an agent transfers a call.
     *
     * A call with no client yet — a handler still Idle, an inbound call on a number we do
     * not recognise — is left out of every count rather than lumped into a default.
     *
     * A FOURTH value rides alongside them (Longest Wait LW-2): the moment the caller who
     * has been holding longest arrived. A MOMENT, not a duration — a duration would be
     * wrong by however long the note sat in the pigeonhole, and an arrival time never goes
     * stale, so whoever reads it works out the wait against their own clock.
     *
     * 🔴 WHO COUNTS AS STILL HOLDING IS WIDER THAN THE `waiting` NUMBER, deliberately. It
     * is every inbound caller who has not yet reached an agent — the ones in the waiting
     * room AND the one whose agent's phone is ringing right now. From the caller's side
     * those are the same experience, it is the same clock the maximum-hold cap already
     * measures (heldTooLong reads both states), and tying it to `waiting` alone would make
     * the number blink out every time a desk was tried: our own callers cycle between
     * holding and ringing once per ring for as long as they wait.
     *
     * Outbound is excluded by construction rather than by a rule — the console places
     * those calls and nothing stamps an arrival on them, so startedAt is null.
     *
     * @return array<int, array{active: int, ringing: int, waiting: int, oldestWaitingAt: int|null}>
     */
    public function tallyByTenant(): array
    {
        $counts = [];

        foreach ($this->uniqueHandlers() as $handler) {
            $tenantId = $handler->tenantId();
            $state = $handler->state();

            $number = match ($state) {
                CallFlowState::InCall, CallFlowState::AddingAgent => 'active',
                CallFlowState::RingingAgent, CallFlowState::RingingCustomer => 'ringing',
                CallFlowState::Waiting => 'waiting',
                // A progressive dial nobody has answered yet belongs in no column: there
                // is no caller on the line and no desk's phone is ringing (DIAL-1 A4).
                // 🔴 An arm, not a default. This match is exhaustive on purpose, so the
                // NEXT state added still has to be given a home here deliberately — and
                // adding one without it throws on every publish, which rescue() swallows
                // into a log while the whole box's board silently stops updating.
                CallFlowState::DialingCustomer => null,
                // A caller being told the client is closed belongs in no column either
                // (inbound-audio slice 4). They are answered, but nobody is talking to
                // them, no desk is ringing, and they are not in the waiting area — so
                // counting them anywhere would overstate one of the three numbers and,
                // for `waiting`, would feed a longest-wait clock nobody is waiting on.
                CallFlowState::PlayingClosedMessage => null,
                // A caller standing at the menu belongs in no column either (slice 6,
                // Ankit S169). Nobody is talking to them, no desk is ringing, and they
                // are not queueing for one — they are being asked a question. Counting
                // them as waiting would tell the floor to pull agents off wrap-up for
                // people who have not chosen anything, and would start the longest-wait
                // clock on someone nobody is meant to be fetching. Nothing is lost by
                // leaving them out: the clock runs from ARRIVAL, so the seconds they
                // spent choosing appear the moment they do join the queue.
                CallFlowState::InMenu, CallFlowState::PlayingMenuMessage => null,
                CallFlowState::Idle => null,
            };

            if ($tenantId === null || $number === null) {
                continue;
            }

            $counts[$tenantId] ??= ['active' => 0, 'ringing' => 0, 'waiting' => 0, 'oldestWaitingAt' => null];
            $counts[$tenantId][$number]++;

            $stillHolding = $state === CallFlowState::Waiting || $state === CallFlowState::RingingAgent;
            $arrivedAt = $stillHolding ? $handler->startedAt()?->getTimestamp() : null;

            if ($arrivedAt !== null) {
                $oldest = $counts[$tenantId]['oldestWaitingAt'];
                $counts[$tenantId]['oldestWaitingAt'] = $oldest === null ? $arrivedAt : min($oldest, $arrivedAt);
            }
        }

        return $counts;
    }

    /**
     * How many live calls the switchboard is holding (distinct handlers, since a call
     * holds two legs). A monitoring hook for the FD-8 "stranded calls" scale-out signal,
     * and the proof a disposed handler is truly forgotten (its legs leave the phone-book).
     */
    public function activeCallCount(): int
    {
        return count($this->uniqueHandlers());
    }

    /**
     * Close the switchboard down: end every live call, hand back every desk, and write
     * the row for anyone who never reached an agent (DIAL-1 R8). Called when the process
     * is asked to stop — a deploy or a systemd restart — while the line to Asterisk is
     * still up.
     *
     * 🔴 The alternative is not "nothing happens", it is a phone that goes on ringing a
     * customer nobody will answer for. Asterisk keeps the channels when our connection
     * goes, so without this a dial in flight rings on, the customer says hello, and the
     * NEXT listener hangs up on them — an answered call ended in a second, which is
     * exactly what DP-12a counts against the 3% cap. Ending the ring before it is
     * answered leaves nothing to count.
     *
     * Best-effort per call, so one stubborn leg cannot keep the rest ringing. Ordinary
     * teardown otherwise: discard() is the same tidy-up the failure backstop uses.
     */
    public function drain(): void
    {
        foreach ($this->uniqueHandlers() as $handler) {
            rescue(fn () => $handler->discard(), report: false);
            $this->release($handler);
        }
    }

    /**
     * Every desk held by a live call, by client (DIAL-1 R8). The reaper's safe list: an
     * agent tagged On a call who appears here is genuinely busy on a call this process is
     * running, and one who does not appear is held by nothing we know of.
     *
     * A handler with no client yet (an inbound leg whose dialled number was not
     * recognised) is skipped — it holds no reservation either, because booking a desk
     * needs a client to book it in.
     *
     * @return array<int, array<int, int>>
     */
    public function heldDesksByTenant(): array
    {
        $held = [];

        foreach ($this->uniqueHandlers() as $handler) {
            $tenantId = $handler->tenantId();

            if ($tenantId === null) {
                continue;
            }

            foreach ($handler->heldAgentIds() as $agentUserId) {
                $held[$tenantId][$agentUserId] = $agentUserId;
            }
        }

        return array_map(array_values(...), $held);
    }

    /**
     * The live calls, one entry each, in arrival order — the phone-book holds a handler
     * once per leg it owns, so the same call appears two or three times. First-seen wins,
     * which keeps the order the calls actually arrived in.
     *
     * @return array<int, CallToAgentFlow>
     */
    private function uniqueHandlers(): array
    {
        $unique = [];

        foreach ($this->handlers as $handler) {
            $unique[spl_object_id($handler)] ??= $handler;
        }

        return array_values($unique);
    }
}
