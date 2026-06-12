<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\Telephony\CallAnswered;
use App\Events\Telephony\CallEnded;
use App\Events\Telephony\CallRinging;
use App\Events\Telephony\RecordingFailed;
use App\Jobs\MergeCallRecordingJob;
use App\Telephony\AriConnectionLost;
use App\Telephony\AriWebSocket;
use App\Telephony\TelephonyException;
use App\Telephony\TelephonyProvider;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The event side of B1 (D2/D5): one long-lived process holding the ARI
 * WebSocket open. Connecting is what registers our Stasis app — while this
 * command is down, inbound voice is down (calls into Stasis just end), so in
 * production it runs under a supervisor with auto-restart, queue-worker
 * style. The in-process reconnect loop rides over network blips.
 *
 * D5's dividing rule, applied: call control a human is waiting on happens
 * inline here; everything heavy (the recording merge) leaves through the
 * queue.
 */
#[Signature('telephony:listen')]
#[Description('Hold the ARI event pipe open: register the app with Asterisk, translate engine events into app events, and run the call flow')]
class TelephonyListen extends Command
{
    /** Reconnect backoff bounds — doubles per failure, resets on a good connection. */
    private const BACKOFF_INITIAL_SECONDS = 1;

    private const BACKOFF_MAX_SECONDS = 30;

    /** Idle thresholds: ping after a minute of silence, give up at ninety seconds. */
    private const PING_AFTER_IDLE_SECONDS = 60;

    private const DEAD_AFTER_IDLE_SECONDS = 90;

    /** Read window per loop tick. */
    private const READ_TIMEOUT_SECONDS = 5.0;

    /**
     * Stage-2 demo flow constants (the lab's extensions and timings). B2
     * replaces the scripted flow with real call flows; these leave with it.
     */
    private const DEMO_AGENT_ENDPOINT = 'PJSIP/1001';

    private const DEMO_TRANSFER_EXTENSION = '600';

    private const DEMO_TALK_SECONDS = 10.0;

    private const DEMO_ECHO_SECONDS = 5.0;

    public function __construct(private readonly TelephonyProvider $telephony)
    {
        parent::__construct();
    }

    public function handle(): void
    {
        $backoff = self::BACKOFF_INITIAL_SECONDS;

        while (true) {
            $pipe = $this->makePipe();

            try {
                $pipe->connect();
                $this->info(sprintf(
                    'Connected — app "%s" is registered with Asterisk; inbound calls now reach us.',
                    config('telephony.asterisk.app'),
                ));
                $backoff = self::BACKOFF_INITIAL_SECONDS;

                $this->listen($pipe);
            } catch (TelephonyException $exception) {
                $this->error("Event pipe lost: {$exception->getMessage()} — reconnecting in {$backoff}s.");
                $pipe->close();
                sleep($backoff);
                $backoff = min($backoff * 2, self::BACKOFF_MAX_SECONDS);
            }
        }
    }

    private function makePipe(): AriWebSocket
    {
        return new AriWebSocket(
            host: config('telephony.asterisk.host'),
            port: config('telephony.asterisk.port'),
            username: config('telephony.asterisk.username'),
            password: config('telephony.asterisk.password'),
            app: config('telephony.asterisk.app'),
        );
    }

    /**
     * The main loop — only leaves by throwing (connection loss). Handles one
     * call at a time: a second caller arriving mid-demo is announced
     * (CallRinging) but not driven. Concurrent calls are B2's flow table.
     */
    private function listen(AriWebSocket $pipe): void
    {
        while (true) {
            $event = $pipe->readEvent(self::READ_TIMEOUT_SECONDS);

            if ($event === null) {
                $this->assertPipeAlive($pipe);

                continue;
            }

            $this->translate($event);

            // A fresh outside call (no tag = not a leg we placed) — drive it.
            if (($event['type'] ?? '') === 'StasisStart' && ($event['args'] ?? null) === []) {
                $this->runDemoCall($pipe, $event['channel']['id']);
            }
        }
    }

    private function assertPipeAlive(AriWebSocket $pipe): void
    {
        $idle = $pipe->secondsSinceLastFrame();

        if ($idle > self::DEAD_AFTER_IDLE_SECONDS) {
            throw new AriConnectionLost(sprintf('No frames for %.0f seconds — assuming a dead connection.', $idle));
        }

        if ($idle > self::PING_AFTER_IDLE_SECONDS) {
            $pipe->ping();      // any reply resets the idle clock
        }
    }

    /**
     * ARI events in, app events out — the same altitude as the provider's
     * verbs (B1 D3): the rest of the app never sees a raw engine payload.
     * Snoop legs (our own recording taps) are infrastructure, not calls.
     *
     * @param  array<string, mixed>  $event
     */
    private function translate(array $event): void
    {
        $this->logEvent($event);

        if (str_starts_with($event['channel']['name'] ?? '', 'Snoop/')) {
            return;
        }

        match ($event['type'] ?? '') {
            'StasisStart' => ($event['args'] ?? null) === []
                ? CallRinging::dispatch($event['channel']['id'], $event['channel']['caller']['number'] ?? null)
                : null,
            'ChannelStateChange' => ($event['channel']['state'] ?? '') === 'Up'
                ? CallAnswered::dispatch($event['channel']['id'])
                : null,
            'ChannelDestroyed' => CallEnded::dispatch($event['channel']['id']),
            'RecordingFailed' => RecordingFailed::dispatch(
                $event['recording']['name'] ?? 'unknown',
                'the engine refused the recording', // the refusal arrives async, after a 2xx
            ),
            default => null,
        };
    }

    /**
     * A3 Stage 2's proof flow — the lab's Stage-1 script, run by the app:
     * answer → dial the agent → join → record per D4 → let them talk → stop
     * recording → queue the stereo merge → transfer the caller → hang up.
     * Scripted timings on purpose; B2 replaces this with real flows.
     */
    private function runDemoCall(AriWebSocket $pipe, string $callerLegId): void
    {
        $this->info("Caller arrived ({$callerLegId}) — running the demo flow.");

        try {
            $this->telephony->answer($callerLegId);

            $this->telephony->placeCall(self::DEMO_AGENT_ENDPOINT, 'agent');
            $agentArrival = $this->waitFor(
                $pipe,
                fn (array $event): bool => ($event['type'] ?? '') === 'StasisStart' && ($event['args'] ?? null) === ['agent'],
                30.0,
            );

            if ($agentArrival === null) {
                $this->warn('Agent did not pick up — ending the call.');
                $this->telephony->hangup($callerLegId);

                return;
            }

            $agentLegId = $agentArrival['channel']['id'];
            $conversationId = $this->telephony->join($callerLegId, $agentLegId);
            $this->info('Caller and agent are in one conversation.');

            $recording = $this->telephony->startRecording($callerLegId, 'call-'.now()->format('Ymd-His'));
            $this->info("Recording both sides as \"{$recording->name}\" — letting them talk for ".self::DEMO_TALK_SECONDS.'s.');
            $this->drain($pipe, self::DEMO_TALK_SECONDS);

            $this->telephony->stopRecording($recording);

            // Events are facts: queue the merge only once Asterisk SAYS both
            // files are finished, not because our stop request got a 2xx.
            $finished = [];
            $confirmed = $this->waitFor($pipe, function (array $event) use ($recording, &$finished): bool {
                if (($event['type'] ?? '') === 'RecordingFinished') {
                    $finished[$event['recording']['name'] ?? ''] = true;
                }

                return isset($finished[$recording->saidRecordingName()], $finished[$recording->heardRecordingName()]);
            }, 10.0);

            if ($confirmed === null) {
                RecordingFailed::dispatch($recording->name, 'Asterisk never confirmed the recordings finished');
                $this->warn('Recordings were not confirmed — skipping the merge.');
            } else {
                MergeCallRecordingJob::dispatch($callerLegId, $recording->name);
                $this->info('Both recordings confirmed — stereo merge queued.');
            }

            $this->telephony->transfer($callerLegId, $conversationId, self::DEMO_TRANSFER_EXTENSION);
            $this->info('Caller transferred to extension '.self::DEMO_TRANSFER_EXTENSION.' (echo test).');

            $this->telephony->hangup($agentLegId);
            $this->telephony->endConversation($conversationId);

            $this->drain($pipe, self::DEMO_ECHO_SECONDS);
            $this->telephony->hangup($callerLegId);

            $this->info('Demo flow complete — answer, dial, join, record, transfer, hang up all ran from the app.');
        } catch (AriConnectionLost $exception) {
            throw $exception;   // the pipe is gone — the reconnect loop owns that
        } catch (TelephonyException $exception) {
            $this->error("Demo flow aborted: {$exception->getMessage()}");
            rescue(fn () => $this->telephony->hangup($callerLegId), report: false);
        }
    }

    /**
     * Read (and translate) events until one matches, or null at the deadline.
     *
     * @param  callable(array<string, mixed>): bool  $match
     * @return array<string, mixed>|null
     */
    private function waitFor(AriWebSocket $pipe, callable $match, float $timeoutSeconds): ?array
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (($remaining = $deadline - microtime(true)) > 0) {
            $event = $pipe->readEvent(min($remaining, self::READ_TIMEOUT_SECONDS));

            if ($event === null) {
                continue;
            }

            $this->translate($event);

            if ($match($event)) {
                return $event;
            }
        }

        return null;
    }

    /** Keep listening (and translating) for a fixed window — the "let them talk" pause. */
    private function drain(AriWebSocket $pipe, float $seconds): void
    {
        $this->waitFor($pipe, fn (): bool => false, $seconds);
    }

    /** One readable line per engine event, so a whole call is followable in the terminal. */
    private function logEvent(array $event): void
    {
        $label = $event['type'] ?? '?';

        if (isset($event['channel'])) {
            $label .= " — {$event['channel']['name']} ({$event['channel']['state']})";
        } elseif (isset($event['recording'])) {
            $label .= " — {$event['recording']['name']} ({$event['recording']['state']})";
        }

        $this->line("  event: {$label}");
    }
}
