<?php

declare(strict_types=1);

namespace App\Telephony\Flows;

use App\Jobs\MergeCallRecordingJob;
use App\Telephony\AriConnectionLost;
use App\Telephony\RecordingSession;
use App\Telephony\TelephonyException;
use App\Telephony\TelephonyProvider;
use Illuminate\Support\Facades\Log;

/**
 * The one call flow B4 v1 draws properly (D5): an outside caller reaches a
 * browser agent. This replaces the scripted demo that used to live inside
 * TelephonyListen — no fixed timings, no sleep/wait/drain. It reacts to raw
 * engine events as the listener hands them over and keeps a little state about
 * the single call in flight.
 *
 * The shape (CP2a):
 *   caller arrives  -> answer the caller, ring the agent's extension
 *   agent answers   -> join the two legs + record both sides (D4 snoops)
 *   either hangs up -> stop recording, drop the survivor, fold the conversation
 *   both files done  -> queue the stereo merge
 *
 * No-answer needs no timer of ours (B4 nod c): placeCall carries Asterisk's own
 * 30s originate timeout, so an unanswered or declined agent leg simply ends and
 * we see its ChannelDestroyed while still ringing — that is the no-answer signal.
 *
 * One call at a time (v1): a second caller arriving mid-call is announced by the
 * listener's translate() but is not driven here. Concurrency is B2.
 */
class InboundToAgentFlow
{
    private InboundFlowState $state = InboundFlowState::Idle;

    private ?string $callerLegId = null;

    private ?string $agentLegId = null;

    private ?string $conversationId = null;

    private ?RecordingSession $recording = null;

    /**
     * Recordings whose merge is waiting on Asterisk to confirm both files
     * finished. Kept off the state machine so the next call is never blocked and
     * we need no timer: each RecordingFinished event ticks an entry, and the
     * merge fires only once both sides are in.
     *
     * @var array<string, array{callId: string, recording: RecordingSession, finished: array<string, true>}>
     */
    private array $pendingMerges = [];

    public function __construct(private readonly TelephonyProvider $telephony) {}

    /**
     * Feed the flow one raw engine event. It never throws on a refused verb — a
     * mid-call telephony error aborts that one call and the flow resets, so the
     * listener keeps running. A lost pipe (AriConnectionLost) is the listener's
     * problem and is re-thrown untouched.
     *
     * @param  array<string, mixed>  $event
     */
    public function handle(array $event): void
    {
        try {
            match ($event['type'] ?? '') {
                'StasisStart' => $this->onArrival($event),
                'ChannelDestroyed' => $this->onLegEnded($event),
                'RecordingFinished' => $this->onRecordingFinished($event),
                default => null,
            };
        } catch (AriConnectionLost $exception) {
            throw $exception;
        } catch (TelephonyException $exception) {
            $this->abort($exception);
        }
    }

    /**
     * A leg entered our Stasis app. Three kinds arrive here, told apart by the
     * tag we placed them with: our recording taps ('snoop') are infrastructure;
     * the agent leg ('agent') means pickup; an untagged leg is an outside caller.
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
            if ($this->state === InboundFlowState::RingingAgent && $legId === $this->agentLegId) {
                $this->connectAgent();
            }

            return;
        }

        if ($args === [] && $this->state === InboundFlowState::Idle) {
            $this->beginCall($legId);
        }
    }

    /** Answer the caller and ring the agent's extension (D7 config map). */
    private function beginCall(string $callerLegId): void
    {
        $this->callerLegId = $callerLegId;

        $this->telephony->answer($callerLegId);
        $this->agentLegId = $this->telephony->placeCall(config('telephony.agent.endpoint'), 'agent');
        $this->state = InboundFlowState::RingingAgent;

        Log::info('Inbound call: caller answered, ringing the agent.', [
            'caller' => $callerLegId,
            'agent' => $this->agentLegId,
        ]);
    }

    /** Agent picked up: put both in one conversation and record both sides (D4). */
    private function connectAgent(): void
    {
        $this->conversationId = $this->telephony->join($this->callerLegId, $this->agentLegId);
        $this->recording = $this->telephony->startRecording(
            $this->callerLegId,
            'call-'.now()->format('Ymd-His'),
        );
        $this->state = InboundFlowState::InCall;

        Log::info('Inbound call connected and recording.', ['recording' => $this->recording->name]);
    }

    /**
     * A leg ended. What it means depends on where we are: while ringing it is a
     * no-answer (the agent leg) or an abandoned caller; in a call it is a hang-up.
     * Snoop legs and a freshly-dropped survivor never match a tracked id, so they
     * fall through untouched.
     *
     * @param  array<string, mixed>  $event
     */
    private function onLegEnded(array $event): void
    {
        $legId = $event['channel']['id'] ?? '';

        if ($this->state === InboundFlowState::RingingAgent) {
            if ($legId === $this->agentLegId) {
                $this->telephony->hangup($this->callerLegId);
                $this->reset();
            } elseif ($legId === $this->callerLegId) {
                $this->telephony->hangup($this->agentLegId);
                $this->reset();
            }

            return;
        }

        if ($this->state === InboundFlowState::InCall
            && ($legId === $this->callerLegId || $legId === $this->agentLegId)) {
            $this->endCall($legId);
        }
    }

    /**
     * Tear the call down. Recording is stopped first (it produces the
     * RecordingFinished facts the merge waits on) and the merge is registered
     * before the best-effort cleanup, so a survivor that has already vanished
     * can never cost us the recording.
     */
    private function endCall(string $endedLegId): void
    {
        $recording = $this->recording;
        $callId = (string) $this->callerLegId;
        $conversationId = (string) $this->conversationId;
        $survivorLegId = (string) ($endedLegId === $this->callerLegId ? $this->agentLegId : $this->callerLegId);

        $this->telephony->stopRecording($recording);

        $this->pendingMerges[$recording->name] = [
            'callId' => $callId,
            'recording' => $recording,
            'finished' => [],
        ];

        $this->reset();

        rescue(fn () => $this->telephony->hangup($survivorLegId), report: false);
        rescue(fn () => $this->telephony->endConversation($conversationId), report: false);
    }

    /**
     * One recording file finished. Tick its entry; queue the stereo merge only
     * once both sides are confirmed (events are facts; a 2xx on the stop request
     * is not). Runs in any state so a recording that confirms after the next
     * call has started still merges.
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

    /** A verb was refused mid-call: drop any legs we still hold, then reset. */
    private function abort(TelephonyException $exception): void
    {
        Log::warning('Inbound flow aborted after a telephony error; resetting.', [
            'error' => $exception->getMessage(),
        ]);

        foreach (array_filter([$this->callerLegId, $this->agentLegId]) as $legId) {
            rescue(fn () => $this->telephony->hangup($legId), report: false);
        }

        $this->reset();
    }

    /** Back to Idle, ready for the next caller. Pending merges outlive a call. */
    private function reset(): void
    {
        $this->state = InboundFlowState::Idle;
        $this->callerLegId = null;
        $this->agentLegId = null;
        $this->conversationId = null;
        $this->recording = null;
    }
}
