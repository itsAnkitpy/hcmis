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
     * every other per-leg event is routed to the leg's handler, or harmlessly dropped
     * if no handler owns it (exactly today's fall-through).
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
     *  - anything else with no known leg        -> dropped (harmless).
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
        }
    }

    /**
     * Hand one event to one handler behind the failure backstop (FD-6). A known
     * telephony error is already handled inside the handler (it tidies its own call);
     * this catches the UNEXPECTED — a bug — and tears down only that one call, logging
     * it, so its siblings keep running. "The line dropped" (AriConnectionLost) is
     * re-thrown untouched: it is everyone's problem and drives the reconnect.
     *
     * @param  array<string, mixed>  $event
     */
    private function dispatch(CallToAgentFlow $handler, array $event): void
    {
        try {
            $handler->handle($event);
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
     * How many live calls the switchboard is holding (distinct handlers, since a call
     * holds two legs). A monitoring hook for the FD-8 "stranded calls" scale-out signal,
     * and the proof a disposed handler is truly forgotten (its legs leave the phone-book).
     */
    public function activeCallCount(): int
    {
        return count(array_unique(array_map('spl_object_id', $this->handlers), SORT_NUMERIC));
    }
}
