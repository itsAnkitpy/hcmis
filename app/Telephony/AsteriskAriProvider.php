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
    public function placeCall(string $destination, string $tag, ?string $callerId = null, int $timeoutSeconds = 30, ?string $tagDetail = null): string
    {
        $params = [
            'endpoint' => $destination,
            'app' => config('telephony.asterisk.app'),
            // ARI comma-splits appArgs into the StasisStart.args array (CP-O0):
            // "agent,1002" arrives as ["agent", "1002"], so the tag detail rides
            // back to the listener as args[1] with no side channel.
            'appArgs' => $tagDetail !== null ? "{$tag},{$tagDetail}" : $tag,
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

    public function transfer(string $legId, string $conversationId, string $destination): void
    {
        $this->command('POST', "/bridges/{$conversationId}/removeChannel", ['channel' => $legId]);

        $this->command('POST', "/channels/{$legId}/continue", [
            'context' => config('telephony.asterisk.context'),
            'extension' => $destination,
            'priority' => 1,
        ]);
    }

    public function hangup(string $legId): void
    {
        $this->command('DELETE', "/channels/{$legId}");
    }

    public function endConversation(string $conversationId): void
    {
        $this->command('DELETE', "/bridges/{$conversationId}");
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

    /**
     * A snoop is a silent listener leg Asterisk attaches to a call leg — the
     * only way ARI allows recording a leg that sits in a conversation (a
     * direct record is refused; 2026-06-12 experiment). spy=in tapes what
     * that party says, spy=out what they hear — both tone-verified. The
     * 'snoop' tag tells the listener these are our taps, not calls.
     */
    private function snoop(string $legId, string $direction): string
    {
        return (string) $this->command('POST', "/channels/{$legId}/snoop", [
            'spy' => $direction,
            'app' => config('telephony.asterisk.app'),
            'appArgs' => 'snoop',
        ])->json('id');
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
     * One ARI command. Parameters ride the query string — that is how ARI
     * takes them. A 2xx only means "Asterisk heard me"; whether it actually
     * happened arrives later on the event pipe.
     *
     * @param  array<string, int|string>  $params
     */
    private function command(string $method, string $path, array $params = []): Response
    {
        if ($params !== []) {
            $path .= '?'.http_build_query($params);
        }

        try {
            return Http::baseUrl(sprintf(
                'http://%s:%d/ari',
                config('telephony.asterisk.host'),
                config('telephony.asterisk.port'),
            ))
                ->withBasicAuth(config('telephony.asterisk.username'), config('telephony.asterisk.password'))
                ->connectTimeout(5)
                ->timeout(10)
                ->send($method, $path)
                ->throw();
        } catch (RequestException $exception) {
            throw new TelephonyException(
                "Asterisk refused {$method} {$path} — HTTP {$exception->response->status()}: {$exception->response->body()}",
                previous: $exception,
            );
        } catch (ConnectionException $exception) {
            throw new TelephonyException(
                "Cannot reach Asterisk for {$method} {$path}: {$exception->getMessage()}",
                previous: $exception,
            );
        }
    }
}
