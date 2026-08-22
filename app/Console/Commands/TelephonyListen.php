<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\Telephony\CallAnswered;
use App\Events\Telephony\CallEnded;
use App\Events\Telephony\CallRinging;
use App\Events\Telephony\RecordingFailed;
use App\Telephony\AriConnectionLost;
use App\Telephony\AriWebSocket;
use App\Telephony\Flows\Switchboard;
use App\Telephony\LiveCallCounts;
use App\Telephony\TelephonyException;
use App\Telephony\TelephonyProvider;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The event side of B1 (D2/D5): one long-lived process holding the ARI
 * WebSocket open. Connecting is what registers our Stasis app — while this
 * command is down, inbound voice is down (calls into Stasis just end), so in
 * production it runs under a supervisor with auto-restart, queue-worker style.
 * The in-process reconnect loop rides over network blips.
 *
 * It is transport + translation only (B4 D5): it reads engine events, turns
 * them into app events (translate()), and hands each raw event to the switchboard
 * that drives call control across many concurrent calls (B2.1).
 *
 * 🔴 ONE switchboard for the life of the process, NOT one per connection (S88 review #2).
 * It used to be rebuilt on every reconnect, on the assumption that a dropped pipe means
 * the calls are gone too. Asterisk does the opposite: losing the websocket only makes our
 * app INACTIVE — new calls cannot enter it, but "existing ones remain in it", and their
 * events resume for us the moment the pipe is back
 * (https://community.asterisk.org/t/problem-with-websockets-and-stasis-app-not-active/87295).
 *
 * So every caller we were holding is still on the line, still hearing music — and a fresh
 * switchboard had never heard of them. Nothing swept them, the client's maximum hold never
 * fired, no missed-call row was ever written, and any agent whose phone was ringing at that
 * instant stayed tagged "On a call" for good. They simply held until they gave up, invisible
 * to every call report. On this box the pipe drops roughly every thirty minutes.
 *
 * Keeping the switchboard is also self-cleaning in the bad case: if the calls really did go
 * away (Asterisk itself restarted), the next thing we ask of each stale call is refused, and
 * a refused verb already tears that one call down properly — record written, agent freed.
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
     * How often the waiting room is glanced at (B2.3b-i QD-3). On a quiet line the read
     * above already paces the loop at five seconds, which is what sets the real ceiling
     * on how long a caller holds after a desk frees up; this only stops a BUSY line —
     * where events arrive constantly — from asking the who's-free board the same
     * question hundreds of times a second.
     */
    private const SWEEP_EVERY_SECONDS = 1.0;

    /**
     * How often the three live call numbers are left where the website can read them
     * (Call Stats CS-2b). Its own gate for the same reason the sweep above has one: the
     * five-second read window only paces a QUIET line, and a busy floor — the one where
     * these numbers matter most — would otherwise write to the cache, a database table
     * here, hundreds of times a second. The board refreshes every fifteen seconds and a
     * note lives for twenty, so nothing downstream can tell the difference.
     */
    private const PUBLISH_EVERY_SECONDS = 5.0;

    /**
     * How often this process leaves a mark saying it is still turning (S119 A1). Read from
     * outside by deploy/telephony-watchdog.sh, which restarts a listener whose mark has gone
     * stale. Gated for the same reason the two above are: on a busy line the loop turns per
     * event, and a file's timestamp only has a second to give anyway.
     */
    private const BEAT_EVERY_SECONDS = 5.0;

    /** When the waiting room was last swept (a monotonic-enough clock for a 1s gate). */
    private float $lastSweptAt = 0.0;

    /** When the call counts were last published (the same kind of clock, same job). */
    private float $lastPublishedAt = 0.0;

    /** When the heartbeat file was last touched (the same kind of clock, same job). */
    private float $lastBeatAt = 0.0;

    public function __construct(
        private readonly TelephonyProvider $telephony,
        private readonly LiveCallCounts $counts,
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        $backoff = self::BACKOFF_INITIAL_SECONDS;
        // Built ONCE, outside the reconnect loop: the calls survive a dropped pipe, so our
        // memory of them has to as well (S88 review #2 — see the note on this class).
        $switchboard = new Switchboard($this->telephony);

        while (true) {
            // Beat here as well as in listen(): a process sitting out a reconnect backoff is
            // healthy, and restarting it would throw away every live call the switchboard holds.
            $this->beat();

            $pipe = $this->makePipe();

            try {
                $pipe->connect();
                $this->info(sprintf(
                    'Connected — app "%s" is registered with Asterisk; inbound calls now reach us.',
                    config('telephony.asterisk.app'),
                ));
                $backoff = self::BACKOFF_INITIAL_SECONDS;

                $this->listen($pipe, $switchboard);
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
     * both translated into app events and handed to the switchboard for call control;
     * the two are independent readers of the same event.
     *
     * The waiting-room sweep (B2.3b-i QD-3) rides this loop on EVERY pass, deliberately
     * not off the "nothing arrived" branch below: that branch only fires when the line is
     * idle, and a switch busy enough to leave callers waiting is exactly the one where it
     * never runs.
     */
    private function listen(AriWebSocket $pipe, Switchboard $switchboard): void
    {
        while (true) {
            $event = $pipe->readEvent(self::READ_TIMEOUT_SECONDS);

            $this->beat();
            $this->sweepWaitingCallers($switchboard);
            $this->publishCallCounts($switchboard);

            if ($event === null) {
                $this->assertPipeAlive($pipe);

                continue;
            }

            $this->translate($event);
            $switchboard->handle($event);
        }
    }

    /** Glance at the waiting room, at most once every SWEEP_EVERY_SECONDS (QD-3). */
    private function sweepWaitingCallers(Switchboard $switchboard): void
    {
        $now = microtime(true);

        if ($now - $this->lastSweptAt < self::SWEEP_EVERY_SECONDS) {
            return;
        }

        $this->lastSweptAt = $now;
        $switchboard->sweepWaiting();
    }

    /**
     * Leave the three live call numbers where the website can read them, at most once
     * every PUBLISH_EVERY_SECONDS (Call Stats CS-2b). Nothing new runs for this: it rides
     * the loop that is already going round. Telling a client whose last call just ended
     * that it is now at zero is the pigeonhole's own job (LiveCallCounts::publish).
     *
     * Best-effort, the shape the missed-call writer already uses: a cache write that fails
     * must never take the listener — and every live call with it — down with it. The notes
     * expire on their own, so the worst a missed publish costs is a board that goes back to
     * saying the phone service is not reporting.
     */
    private function publishCallCounts(Switchboard $switchboard): void
    {
        $now = microtime(true);

        if ($now - $this->lastPublishedAt < self::PUBLISH_EVERY_SECONDS) {
            return;
        }

        $this->lastPublishedAt = $now;

        rescue(
            fn () => $this->counts->publish($switchboard->tallyByTenant()),
            fn (Throwable $exception) => Log::warning('Live call counts were not published — the board will say the phone service is not reporting.', [
                'error' => $exception->getMessage(),
            ]),
        );
    }

    /**
     * Leave a mark saying this process is still turning (S119 A1).
     *
     * systemd restarts the listener if it DIES (Restart=always). Nothing notices it FREEZING
     * while still alive — the process is in the process list, the pipe may even be open, but
     * the loop has stopped and calls go unanswered in silence. An outside check reads the age
     * of this file and restarts a stale one; see deploy/telephony-watchdog.sh.
     *
     * Best-effort, the shape publishCallCounts() already uses: a full disk must never take the
     * listener — and every live call with it — down with it. The cost of a missed touch is at
     * worst one restart of a healthy process, which the 120s staleness limit leaves room for.
     */
    private function beat(): void
    {
        $now = microtime(true);

        if ($now - $this->lastBeatAt < self::BEAT_EVERY_SECONDS) {
            return;
        }

        $this->lastBeatAt = $now;

        rescue(
            fn () => touch(storage_path('app/telephony-heartbeat')),
            fn (Throwable $exception) => Log::warning('The listener could not leave its heartbeat — the freeze watchdog is blind until this clears.', [
                'error' => $exception->getMessage(),
            ]),
        );
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
        } elseif (isset($event['eventname'])) {
            // The web's control signal (B2.4a TD-4) — show which signal it was (transfer,
            // conference, …) so it is followable in this terminal alongside the call's events.
            $label .= " — {$event['eventname']}";
        }

        $this->line("  event: {$label}");
    }
}
