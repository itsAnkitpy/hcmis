<?php

declare(strict_types=1);

namespace App\Telephony\Flows;

use App\Telephony\AriConnectionLost;
use App\Telephony\RecordingSession;
use App\Telephony\TelephonyException;
use App\Telephony\TelephonyProvider;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
 */
class CallToAgentFlow
{
    private CallFlowState $state = CallFlowState::Idle;

    private ?string $callerLegId = null;

    private ?string $agentLegId = null;

    private ?string $conversationId = null;

    /**
     * The call's tracking number (B3 D3): the UUID the web console minted at dial
     * and rode in as the agent leg's third arg. Used as the recording's callId so
     * RecordingReady carries our UUID (= the calls row's correlation_id), letting
     * the queued listener attach the recording by UUID. Null on inbound (no UUID
     * injected v1, O1) — the recording then falls back to the customer leg id,
     * harmless since inbound recording-attach is trunk-era.
     */
    private ?string $correlationId = null;

    /**
     * The call's ticket number (the per-call id, FD-3): a unique id stamped on every
     * call so many interleaved calls are followable in the log, and — later — the
     * handle the durable waiting-line points at (FD-7). Outbound REUSES the web's
     * tracking number (the UUID above); inbound MINTS a fresh one. Kept SEPARATE from
     * the recording's callId on purpose: inbound recording-attach still skips on a
     * non-UUID leg id (it is trunk-era), so the ticket must not leak into that path.
     */
    private ?string $ticketNumber = null;

    private ?RecordingSession $recording = null;

    public function __construct(
        private readonly TelephonyProvider $telephony,
        private readonly HandlerRegistry $registry,
    ) {}

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
     * @param  array<string, mixed>  $event
     */
    public function handle(array $event): void
    {
        try {
            match ($event['type'] ?? '') {
                'StasisStart' => $this->onArrival($event),
                'ChannelDestroyed' => $this->onLegEnded($event),
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
     *   ['agent']            -> inbound agent pickup (the agent leg we placed).
     *   ['agent', <number>, <uuid?>]
     *                        -> outbound entry: the agent leg, carrying the customer
     *                           number to dial next (agent-first, CP-O0) and the
     *                           call's tracking number (the B3 UUID, CP-B3-2 D3).
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
            if ($this->state === CallFlowState::RingingAgent && $legId === $this->agentLegId) {
                $this->connectAgent();
            }

            return;
        }

        // Outbound entry (agent-first): the agent's own leg arrives first carrying
        // the customer's number as a second arg (and, CP-B3-2, the call's UUID as a
        // third), so we dial the customer now. >= 2 tolerates both the pre-B3 two-arg
        // shape and the three-arg one — the UUID is optional, defaulting to null.
        if (is_array($args) && count($args) >= 2 && $args[0] === 'agent' && $this->state === CallFlowState::Idle) {
            $this->beginOutboundCall($legId, (string) $args[1], isset($args[2]) ? (string) $args[2] : null);

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
            $this->beginCall($legId, $callerNumber !== '' ? $callerNumber : null);
        }
    }

    /**
     * Answer the caller and ring the agent's extension (D7 config map), carrying
     * the caller's own number as the agent leg's caller-ID — the browser reads it
     * off the ringing call to look up the lead (B4 D4).
     */
    private function beginCall(string $callerLegId, ?string $callerNumber = null): void
    {
        $this->callerLegId = $callerLegId;
        $this->ticketNumber = (string) Str::uuid();   // inbound mints a fresh ticket (FD-3)

        $this->telephony->answer($callerLegId);
        $this->agentLegId = $this->telephony->placeCall(
            config('telephony.agent.endpoint'),
            'agent',
            $callerNumber,
        );
        $this->registry->registerLeg($this->agentLegId, $this);   // the leg we placed is ours (FD-2)
        $this->state = CallFlowState::RingingAgent;

        Log::info('Inbound call: caller answered, ringing the agent.', [
            'ticket' => $this->ticketNumber,
            'caller' => $callerLegId,
            'callerNumber' => $callerNumber,
            'agent' => $this->agentLegId,
        ]);
    }

    /**
     * Outbound entry (B-outbound M2): the agent's leg is already up (the web
     * console placed it agent-first); now dial the customer. The customer maps
     * onto callerLegId so the join/record/teardown below reuse unchanged. The
     * bare number rides as a clean arg; the engine-specific endpoint prefix and
     * the single outbound caller-ID (O2) are read from config here.
     */
    private function beginOutboundCall(string $agentLegId, string $customerNumber, ?string $correlationId = null): void
    {
        $this->agentLegId = $agentLegId;
        $this->correlationId = $correlationId;
        // Outbound reuses the web's tracking number as the ticket (FD-3); if a pre-B3
        // two-arg leg carried none, mint one so every call still has a unique ticket.
        $this->ticketNumber = $correlationId ?? (string) Str::uuid();
        $this->callerLegId = $this->telephony->placeCall(
            config('telephony.outbound.dial_prefix').$customerNumber,
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

    /** Agent picked up: put both in one conversation and record both sides (D4). */
    private function connectAgent(): void
    {
        $this->conversationId = $this->telephony->join($this->callerLegId, $this->agentLegId);
        $this->recording = $this->telephony->startRecording(
            $this->callerLegId,
            'call-'.now()->format('Ymd-His'),
        );
        $this->state = CallFlowState::InCall;

        // Register the pending merge the MOMENT recording starts — not at hang-up. When the
        // recorded (caller) leg drops, Asterisk destroys its taps and emits "recording
        // finished" immediately, BEFORE our teardown runs; if the switchboard's notebook does
        // not already hold this call, those events land on an empty desk and the merge is lost
        // (the CP-B2.1 inbound race). The call-id is our UUID when the web injected one
        // (outbound, CP-B3-2), else the caller leg id (inbound — harmless, its attach is trunk-era).
        $callId = $this->correlationId ?? (string) $this->callerLegId;
        $this->registry->depositMerge($callId, $this->recording);

        Log::info('Call connected and recording.', [
            'ticket' => $this->ticketNumber,
            'recording' => $this->recording->name,
        ]);
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

        // While ringing, a leg ending tears the other one down and resets. The
        // shape is identical both directions — end one ringing leg, hang up the
        // other — but what the agent's browser does next differs, and is decided
        // there (on the answered flag), not here:
        //   - inbound (RingingAgent): the agent leg never answered; its end is a
        //     silent reset, the caller leg ending is an abandoned caller — both
        //     drop the survivor and go back to ready.
        //   - outbound (RingingCustomer): the agent leg is already up, so the
        //     CUSTOMER leg ending is the no-answer signal (CP-O2 / D5) — dropping
        //     the answered agent leg flips the browser to wrap-up; the agent leg
        //     ending is the agent abandoning mid-ring, which cancels the customer.
        if ($this->state === CallFlowState::RingingAgent || $this->state === CallFlowState::RingingCustomer) {
            if ($legId === $this->agentLegId) {
                $this->telephony->hangup($this->callerLegId);
                $this->dispose();
            } elseif ($legId === $this->callerLegId) {
                $this->telephony->hangup($this->agentLegId);
                $this->dispose();
            }

            return;
        }

        if ($this->state === CallFlowState::InCall
            && ($legId === $this->callerLegId || $legId === $this->agentLegId)) {
            $this->endCall($legId);
        }
    }

    /**
     * Tear the call down. The pending merge was already registered at connect time
     * (connectAgent), so the recording survives whoever hangs up first. Stopping the
     * recording here is best-effort: when the RECORDED (caller) leg is the one that
     * dropped (inbound hang-up), Asterisk has already finished and destroyed the taps,
     * so an explicit stop would refuse — expected, not an error. When the survivor is
     * the recorded leg (outbound agent hang-up) the explicit stop is what finishes it;
     * and if that ever fails, hanging up the survivor below finishes it anyway.
     */
    private function endCall(string $endedLegId): void
    {
        $recording = $this->recording;
        $conversationId = (string) $this->conversationId;
        $survivorLegId = (string) ($endedLegId === $this->callerLegId ? $this->agentLegId : $this->callerLegId);

        rescue(fn () => $this->telephony->stopRecording($recording), report: false);

        $this->dispose();

        rescue(fn () => $this->telephony->hangup($survivorLegId), report: false);
        rescue(fn () => $this->telephony->endConversation($conversationId), report: false);
    }

    /** A verb was refused mid-call: drop any legs we still hold, then tear this call down. */
    private function abort(TelephonyException $exception): void
    {
        Log::warning('Call aborted after a telephony error; tearing down just this call.', [
            'ticket' => $this->ticketNumber,
            'error' => $exception->getMessage(),
        ]);

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
        $this->hangupHeldLegs();
        $this->reset();
    }

    /** Best-effort hang up whichever of our two legs we still hold. */
    private function hangupHeldLegs(): void
    {
        foreach (array_filter([$this->callerLegId, $this->agentLegId]) as $legId) {
            rescue(fn () => $this->telephony->hangup($legId), report: false);
        }
    }

    /** Wipe this call's state. Part of disposal — a handler is one-per-call now (B2.1). */
    private function reset(): void
    {
        $this->state = CallFlowState::Idle;
        $this->callerLegId = null;
        $this->agentLegId = null;
        $this->conversationId = null;
        $this->correlationId = null;
        $this->ticketNumber = null;
        $this->recording = null;
    }
}
