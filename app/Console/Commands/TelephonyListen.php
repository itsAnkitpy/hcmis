<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\Telephony\CallAnswered;
use App\Events\Telephony\CallEnded;
use App\Events\Telephony\CallRinging;
use App\Events\Telephony\RecordingFailed;
use App\Telephony\AriConnectionLost;
use App\Telephony\AriWebSocket;
use App\Telephony\Flows\InboundToAgentFlow;
use App\Telephony\TelephonyException;
use App\Telephony\TelephonyProvider;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The event side of B1 (D2/D5): one long-lived process holding the ARI
 * WebSocket open. Connecting is what registers our Stasis app — while this
 * command is down, inbound voice is down (calls into Stasis just end), so in
 * production it runs under a supervisor with auto-restart, queue-worker style.
 * The in-process reconnect loop rides over network blips.
 *
 * It is transport + translation only (B4 D5): it reads engine events, turns
 * them into app events (translate()), and hands each raw event to the call flow
 * that drives call control. A fresh flow is built per connection, so a
 * reconnect starts with no half-finished call in hand.
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

                $this->listen($pipe, new InboundToAgentFlow($this->telephony));
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
     * The main loop — only leaves by throwing (connection loss). Each event is
     * both translated into app events and handed to the flow for call control;
     * the two are independent readers of the same event.
     */
    private function listen(AriWebSocket $pipe, InboundToAgentFlow $flow): void
    {
        while (true) {
            $event = $pipe->readEvent(self::READ_TIMEOUT_SECONDS);

            if ($event === null) {
                $this->assertPipeAlive($pipe);

                continue;
            }

            $this->translate($event);
            $flow->handle($event);
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
     * ARI events in, app events out — the same altitude as the provider's verbs
     * (B1 D3): the rest of the app never sees a raw engine payload. Snoop legs
     * (our own recording taps) are infrastructure, not calls.
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
