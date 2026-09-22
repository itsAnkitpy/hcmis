<?php

declare(strict_types=1);

namespace App\Telephony;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The Asterisk 20 + ARI spelling of the telephony verbs (B1 D1/D3): each verb
 * is one or two plain HTTP commands against /ari. Every endpoint path and
 * parameter here was verified against the running container's own API spec
 * (GET /ari/api-docs/*.json, Asterisk 20.19.0) in lab A3 — not against web
 * tutorials.
 */
class AsteriskAriProvider implements TelephonyProvider
{
    public function placeCall(string $destination, string $tag, ?string $callerId = null, int $timeoutSeconds = 30, array $tagDetails = []): string
    {
        $params = [
            'endpoint' => $destination,
            'app' => config('telephony.asterisk.app'),
            // ARI comma-splits appArgs into the StasisStart.args array (CP-O0):
            // "agent,1002,<uuid>" arrives as ["agent", "1002", "<uuid>"], so the tag
            // and each ordered detail ride back to the listener as args[0..n] with no
            // side channel.
            'appArgs' => implode(',', [$tag, ...$tagDetails]),
            'timeout' => $timeoutSeconds,
        ];

        // 'callerId' is ARI's originate param (verified live against the
        // container's channels.json) — only sent when we have one to present.
        if ($callerId !== null) {
            $params['callerId'] = $callerId;
        }

        return (string) $this->command('POST', '/channels', $params)->json('id');
    }

    public function answer(string $legId): void
    {
        $this->command('POST', "/channels/{$legId}/answer");
    }

    public function join(string $legIdA, string $legIdB): string
    {
        $conversationId = (string) $this->command('POST', '/bridges', ['type' => 'mixing'])->json('id');

        $this->command('POST', "/bridges/{$conversationId}/addChannel", ['channel' => "{$legIdA},{$legIdB}"]);

        return $conversationId;
    }

    public function addToBridge(string $conversationId, string $legId): void
    {
        $this->command('POST', "/bridges/{$conversationId}/addChannel", ['channel' => $legId]);
    }

    public function removeFromBridge(string $conversationId, string $legId): void
    {
        $this->command('POST', "/bridges/{$conversationId}/removeChannel", ['channel' => $legId]);
    }

    public function signal(string $name, array $details): void
    {
        // POST /events/user/{name}: 'application' rides the query string, but the
        // custom variables MUST ride the request BODY under a 'variables' key —
        // query-string variables are dropped (verified live against the running
        // container: a body-carried, source-less user-event arrives as a
        // ChannelUserevent with the variables under its 'userevent' object).
        $this->command('POST', "/events/user/{$name}", [
            'application' => config('telephony.asterisk.app'),
        ], ['variables' => $details]);
    }

    public function transfer(string $legId, string $conversationId, string $destination): void
    {
        $this->command('POST', "/bridges/{$conversationId}/removeChannel", ['channel' => $legId]);

        $this->command('POST', "/channels/{$legId}/continue", [
            'context' => config('telephony.asterisk.context'),
            'extension' => $destination,
            'priority' => 1,
        ]);
    }

    /**
     * ARI's music-on-hold pair (B2.3b-i QD-1). With no class named, Asterisk plays the
     * channel's own configured one — the stock `default` class verified on the server
     * (five instrumentals in /usr/share/asterisk/moh), which it holds in memory.
     *
     * A named class is the client's own music (inbound-audio slice 3). Asterisk looks
     * a class up in the database only when it is not already in memory, so a database
     * class is re-read on every hold start — which is why changing a client's music
     * reaches the next caller with no reload (S165 check 4).
     */
    public function startHoldMusic(string $legId, ?string $mohClass = null): void
    {
        $this->command('POST', "/channels/{$legId}/moh", $mohClass === null ? [] : ['mohClass' => $mohClass]);
    }

    public function stopHoldMusic(string $legId): void
    {
        $this->command('DELETE', "/channels/{$legId}/moh");
    }

    /**
     * ARI's playback pair (inbound-audio slice 4). Verified against the 20 branch's own
     * API spec (rest-api/api-docs/channels.json): POST /channels/{id}/play takes `media`
     * as a required query value and answers with a Playback object carrying its `id`.
     *
     * 🔴 ONE COMMA-JOINED VALUE, NOT A REPEATED PARAMETER. Asterisk splits `media` on
     * commas into the play's list; sending `media=a&media=b` keeps only the last (S165).
     *
     * 🔴 EVERY ADDRESS IS PREFIXED `sound:`, AND A BARE WEB ADDRESS SILENTLY PLAYS
     * NOTHING. res_stasis_playback.c matches the value against six known schemes
     * (`sound:`, `recording:`, `number:`, `digits:`, `characters:`, `tone:`) and sends
     * anything else to a branch that logs "scheme is unsupported" and skips it — the
     * play still reports success and still finishes, so the call behaves normally and
     * the caller simply hears silence. Found on staging S167: the voice box never even
     * requested the file. With the prefix, Asterisk strips it and hands the rest to the
     * file player, which treats anything containing `://` as remote and pulls it through
     * the HTTP media cache (main/file.c, is_remote_path / ast_media_cache_retrieve) —
     * the same cache hold music already uses.
     *
     * This is the ONE place the prefix is added, because it is engine spelling: the
     * contract above speaks in plain web addresses, as every other verb here does.
     *
     * @param  array<int, string>  $mediaUris
     */
    public function play(string $legId, array $mediaUris): string
    {
        return (string) $this->command('POST', "/channels/{$legId}/play", [
            'media' => implode(',', array_map(
                static fn (string $uri): string => 'sound:'.$uri,
                $mediaUris,
            )),
        ])->json('id');
    }

    public function stopPlayback(string $playbackId): void
    {
        $this->command('DELETE', "/playbacks/{$playbackId}");
    }

    public function hangup(string $legId, ?string $reason = null): void
    {
        $this->command('DELETE', "/channels/{$legId}", $reason === null ? [] : ['reason' => $reason]);
    }

    public function endConversation(string $conversationId): void
    {
        $this->command('DELETE', "/bridges/{$conversationId}");
    }

    /**
     * Every channel Asterisk currently has up, by name (R8). Not scoped to our app on
     * purpose: the question the reaper asks is "is this agent's phone busy at all", and a
     * channel that has left our app but is still up still means their handset is engaged.
     *
     * @return array<int, string>
     */
    public function liveChannelNames(): array
    {
        return array_values(array_filter(array_map(
            fn (mixed $channel): ?string => is_array($channel) && is_string($channel['name'] ?? null)
                ? $channel['name']
                : null,
            (array) $this->command('GET', '/channels')->json(),
        )));
    }

    public function isAnswered(string $legId): bool
    {
        return $this->command('GET', "/channels/{$legId}")->json('state') === 'Up';
    }

    public function startRecording(string $legId, string $name): RecordingSession
    {
        $session = new RecordingSession(
            legId: $legId,
            name: $name,
            saidSnoopLegId: $this->snoop($legId, 'in'),
            heardSnoopLegId: $this->snoop($legId, 'out'),
        );

        $this->record($session->saidSnoopLegId, $session->saidRecordingName());
        $this->record($session->heardSnoopLegId, $session->heardRecordingName());

        return $session;
    }

    public function stopRecording(RecordingSession $session): void
    {
        $this->command('POST', '/recordings/live/'.$session->saidRecordingName().'/stop');
        $this->command('POST', '/recordings/live/'.$session->heardRecordingName().'/stop');

        $this->command('DELETE', "/channels/{$session->saidSnoopLegId}");
        $this->command('DELETE', "/channels/{$session->heardSnoopLegId}");
    }

    public function fetchRecording(string $recordingName): string
    {
        return $this->command('GET', "/recordings/stored/{$recordingName}/file")->body();
    }

    public function snoop(string $legId, string $spy, string $whisper = 'none'): string
    {
        $params = [
            'spy' => $spy,
            'app' => config('telephony.asterisk.app'),
            'appArgs' => 'snoop',
        ];

        // 🔴 'none' is ARI's own default, so it is LEFT OFF rather than sent. Recording
        // has made this exact request on every call this system has ever carried, and
        // there is no reason to change one byte of it to add a parameter that means
        // "behave as you already do". Whisper rides only when it is actually asked for.
        if ($whisper !== 'none') {
            $params['whisper'] = $whisper;
        }

        return (string) $this->command('POST', "/channels/{$legId}/snoop", $params)->json('id');
    }

    private function record(string $legId, string $name): void
    {
        $this->command('POST', "/channels/{$legId}/record", [
            'name' => $name,
            'format' => 'wav',
            'ifExists' => 'overwrite',
        ]);
    }

    /**
     * One ARI command. Parameters ride the query string — that is how ARI takes
     * most of them. A few endpoints (POST /events/user) want their payload in a
     * JSON request BODY instead; pass $body for those (verified live). A 2xx only
     * means "Asterisk heard me"; whether it actually happened arrives later on the
     * event pipe.
     *
     * @param  array<string, int|string>  $params
     * @param  array<string, mixed>  $body
     */
    private function command(string $method, string $path, array $params = [], array $body = []): Response
    {
        if ($params !== []) {
            $path .= '?'.http_build_query($params);
        }

        try {
            $request = Http::baseUrl(sprintf(
                'http://%s:%d/ari',
                config('telephony.asterisk.host'),
                config('telephony.asterisk.port'),
            ))
                ->withBasicAuth(config('telephony.asterisk.username'), config('telephony.asterisk.password'))
                ->connectTimeout(5)
                ->timeout(10);

            if ($body !== []) {
                $request = $request->withBody(json_encode($body), 'application/json');
            }

            return $request
                ->send($method, $path)
                ->throw();
        } catch (RequestException $exception) {
            $message = "Asterisk refused {$method} {$path} — HTTP {$exception->response->status()}: {$exception->response->body()}";

            // 404 is its own answer, not a general refusal: the channel or playback we
            // named is gone. Callers that know what a missing leg means (the menu) act on
            // it; everyone else still sees a TelephonyException, since AriNotFound is one.
            throw $exception->response->status() === 404
                ? new AriNotFound($message, previous: $exception)
                : new TelephonyException($message, previous: $exception);
        } catch (ConnectionException $exception) {
            throw new TelephonyException(
                "Cannot reach Asterisk for {$method} {$path}: {$exception->getMessage()}",
                previous: $exception,
            );
        }
    }
}
