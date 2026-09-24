<?php

use App\Telephony\RecordingSession;
use App\Telephony\TelephonyException;
use App\Telephony\TelephonyProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Command-side contract (B1 D1/D3): each call-language verb must turn into
 * the exact ARI HTTP commands the lab proved, with basic auth and
 * query-string parameters. Http::fake keeps everything off the network.
 */
/**
 * ARI commands carry their parameters in the query string, so that is where
 * the assertions must look ($request['…'] reads the body, which is empty).
 *
 * @return array<string, string>
 */
function ariParams(Request $request): array
{
    parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $params);

    return $params;
}

beforeEach(function () {
    config()->set('telephony.provider', 'asterisk');
    config()->set('telephony.asterisk', [
        'host' => 'voice.test',
        'port' => 8088,
        'username' => 'ari-user',
        'password' => 'ari-pass',
        'app' => 'hcmis-test',
        'context' => 'internal',
    ]);

    Http::preventStrayRequests();

    $this->telephony = app(TelephonyProvider::class);
});

it('places a call tagged as ours and returns the new leg id', function () {
    Http::fake(['*' => Http::response(['id' => 'leg-agent'])]);

    $legId = $this->telephony->placeCall('PJSIP/1001', 'agent');

    expect($legId)->toBe('leg-agent');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_starts_with($request->url(), 'http://voice.test:8088/ari/channels?')
        && ariParams($request) === [
            'endpoint' => 'PJSIP/1001',
            'app' => 'hcmis-test',
            'appArgs' => 'agent',
            'timeout' => '30',
        ]);
});

it('rides the caller-ID on the originate when one is given (B4 D4)', function () {
    Http::fake(['*' => Http::response(['id' => 'leg-agent'])]);

    $this->telephony->placeCall('PJSIP/1003', 'agent', '9991234567');

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'http://voice.test:8088/ari/channels?')
        && ariParams($request) === [
            'endpoint' => 'PJSIP/1003',
            'app' => 'hcmis-test',
            'appArgs' => 'agent',
            'timeout' => '30',
            'callerId' => '9991234567',
        ]);
});

it('rides the tag detail as a second app-arg so it reaches the listener (outbound C-transport)', function () {
    Http::fake(['*' => Http::response(['id' => 'agent-leg'])]);

    $this->telephony->placeCall('PJSIP/1003', 'agent', tagDetails: ['1002']);

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'http://voice.test:8088/ari/channels?')
        && ariParams($request) === [
            'endpoint' => 'PJSIP/1003',
            'app' => 'hcmis-test',
            'appArgs' => 'agent,1002',
            'timeout' => '30',
        ]);
});

it('rides multiple ordered tag details as further app-args (the UUID seam, CP-B3-2 D3)', function () {
    Http::fake(['*' => Http::response(['id' => 'agent-leg'])]);

    $this->telephony->placeCall('PJSIP/1003', 'agent', tagDetails: ['1002', 'the-uuid']);

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'http://voice.test:8088/ari/channels?')
        && ariParams($request) === [
            'endpoint' => 'PJSIP/1003',
            'app' => 'hcmis-test',
            'appArgs' => 'agent,1002,the-uuid',
            'timeout' => '30',
        ]);
});

it('omits the caller-ID param when none is given', function () {
    Http::fake(['*' => Http::response(['id' => 'leg-agent'])]);

    $this->telephony->placeCall('PJSIP/1003', 'agent');

    Http::assertSent(fn (Request $request): bool => ! array_key_exists('callerId', ariParams($request)));
});

it('sends commands with basic auth', function () {
    Http::fake(['*' => Http::response(['id' => 'leg-1'])]);

    $this->telephony->answer('leg-1');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader(
        'Authorization', 'Basic '.base64_encode('ari-user:ari-pass'),
    ));
});

it('answers a ringing leg', function () {
    Http::fake(['*' => Http::response()]);

    $this->telephony->answer('leg-caller');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://voice.test:8088/ari/channels/leg-caller/answer');
});

it('joins two legs into one conversation and returns its id', function () {
    Http::fake([
        '*/bridges/conv-1/addChannel*' => Http::response(),
        '*/bridges*' => Http::response(['id' => 'conv-1']),
    ]);

    $conversationId = $this->telephony->join('leg-a', 'leg-b');

    expect($conversationId)->toBe('conv-1');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/bridges?')
        && ariParams($request)['type'] === 'mixing');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/bridges/conv-1/addChannel')
        && ariParams($request)['channel'] === 'leg-a,leg-b');
});

it('adds a leg to an existing conversation (B2.4a transfer surgery)', function () {
    Http::fake(['*' => Http::response()]);

    $this->telephony->addToBridge('conv-1', 'leg-b');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_contains($request->url(), '/bridges/conv-1/addChannel')
        && ariParams($request)['channel'] === 'leg-b');
});

it('removes a leg from a conversation without ending it (B2.4a transfer surgery)', function () {
    Http::fake(['*' => Http::response()]);

    $this->telephony->removeFromBridge('conv-1', 'leg-a');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_contains($request->url(), '/bridges/conv-1/removeChannel')
        && ariParams($request)['channel'] === 'leg-a');
});

it('signals the listener with a source-less user-event, variables in the JSON body (B2.4a TD-4)', function () {
    Http::fake(['*' => Http::response(null, 204)]);

    $this->telephony->signal('transfer', ['agentUserId' => '6', 'tenantId' => '3']);

    Http::assertSent(function (Request $request): bool {
        // The app name rides the query (source omitted -> a source-less event); the
        // custom variables ride the BODY under 'variables' (verified live: query-string
        // variables are dropped, the body lands under the received 'userevent' object).
        return $request->method() === 'POST'
            && str_starts_with($request->url(), 'http://voice.test:8088/ari/events/user/transfer?')
            && ariParams($request) === ['application' => 'hcmis-test']
            && $request->data() === ['variables' => ['agentUserId' => '6', 'tenantId' => '3']];
    });
});

it('transfers a leg out of its conversation to another destination', function () {
    Http::fake(['*' => Http::response()]);

    $this->telephony->transfer('leg-caller', 'conv-1', '600');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/bridges/conv-1/removeChannel')
        && ariParams($request)['channel'] === 'leg-caller');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/channels/leg-caller/continue')
        && ariParams($request) === ['context' => 'internal', 'extension' => '600', 'priority' => '1']);
});

it('hangs up a leg', function () {
    Http::fake(['*' => Http::response()]);

    $this->telephony->hangup('leg-caller');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'http://voice.test:8088/ari/channels/leg-caller');
});

it('hangs up a leg with a reason, so an unanswered caller hears busy (inbound-audio AU-2)', function () {
    Http::fake(['*' => Http::response()]);

    $this->telephony->hangup('leg-caller', 'busy');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_starts_with($request->url(), 'http://voice.test:8088/ari/channels/leg-caller?')
        && ariParams($request) === ['reason' => 'busy']);
});

it('ends a conversation', function () {
    Http::fake(['*' => Http::response()]);

    $this->telephony->endConversation('conv-1');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'http://voice.test:8088/ari/bridges/conv-1');
});

it('starts a recording as two snoops on the leg — what they say and what they hear', function () {
    Http::fake([
        // The snoop pattern must name the leg: record URLs also contain the
        // word "snoop" ("…/channels/snoop-said/record") and must not match.
        '*/channels/leg-caller/snoop*' => Http::sequence()
            ->push(['id' => 'snoop-said'])
            ->push(['id' => 'snoop-heard']),
        '*/record*' => Http::response(null, 201),
    ]);

    $session = $this->telephony->startRecording('leg-caller', 'call-1');

    expect($session->legId)->toBe('leg-caller')
        ->and($session->name)->toBe('call-1')
        ->and($session->saidSnoopLegId)->toBe('snoop-said')
        ->and($session->heardSnoopLegId)->toBe('snoop-heard');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/channels/leg-caller/snoop')
        && ariParams($request) === ['spy' => 'in', 'app' => 'hcmis-test', 'appArgs' => 'snoop']);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/channels/leg-caller/snoop')
        && ariParams($request)['spy'] === 'out');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/channels/snoop-said/record')
        && ariParams($request)['name'] === 'call-1-said'
        && ariParams($request)['format'] === 'wav');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/channels/snoop-heard/record')
        && ariParams($request)['name'] === 'call-1-heard');
});

it('leaves the whisper direction off the request entirely when a tap is silent', function () {
    Http::fake(['*/channels/agent-leg/snoop*' => Http::response(['id' => 'tap-leg'])]);

    expect($this->telephony->snoop('agent-leg', 'both'))->toBe('tap-leg');

    // 🔴 'none' is the engine's OWN default, so it is left off rather than sent. A silent
    // tap's request is byte-identical to the one recording has made on every call this
    // system has ever carried, and a proven request does not get edited to add a
    // parameter meaning "behave as you already do".
    Http::assertSent(fn (Request $request): bool => ariParams($request)
        === ['spy' => 'both', 'app' => 'hcmis-test', 'appArgs' => 'snoop']);
});

it('sends the whisper direction that puts a coach in the agents ear, and only then', function () {
    Http::fake(['*/channels/agent-leg/snoop*' => Http::response(['id' => 'tap-leg'])]);

    expect($this->telephony->snoop('agent-leg', 'both', 'out'))->toBe('tap-leg');

    // 'out' is the audio written TO that line — what the agent hears. 'in' would be the
    // audio read FROM it, which is what carries on to the customer.
    Http::assertSent(fn (Request $request): bool => ariParams($request)
        === ['spy' => 'both', 'app' => 'hcmis-test', 'appArgs' => 'snoop', 'whisper' => 'out']);
});

it('stops both sides of a recording and releases the snoop legs', function () {
    Http::fake(['*' => Http::response()]);

    $session = new RecordingSession('leg-caller', 'call-1', 'snoop-said', 'snoop-heard');
    $this->telephony->stopRecording($session);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/recordings/live/call-1-said/stop'));
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/recordings/live/call-1-heard/stop'));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/channels/snoop-said'));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/channels/snoop-heard'));
});

it('records a caller\'s message on their own leg with the engine\'s stops (slice 8, AU-30)', function () {
    Http::fake(['*' => Http::response(null, 201)]);

    $this->telephony->recordMessage('leg-caller', 'voicemail-ticket-1');

    // 🔴 maxSilenceSeconds is also what makes the finish carry talking_duration, which
    // AU-31's empty-message drop reads — never 0.
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_contains($request->url(), '/channels/leg-caller/record')
        && ariParams($request) === [
            'name' => 'voicemail-ticket-1',
            'format' => 'wav',
            'maxDurationSeconds' => '180',
            'maxSilenceSeconds' => '5',
            'terminateOn' => '#',
            'beep' => 'true',
            'ifExists' => 'overwrite',
        ]);
});

it('fetches a stored recording as raw bytes', function () {
    Http::fake(['*' => Http::response('RIFF-wav-bytes')]);

    expect($this->telephony->fetchRecording('call-1-said'))->toBe('RIFF-wav-bytes');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'http://voice.test:8088/ari/recordings/stored/call-1-said/file');
});

it('wraps a refused command in a telephony exception with the engine detail', function () {
    Http::fake(['*' => Http::response('Channel not found', 404)]);

    expect(fn () => $this->telephony->answer('leg-gone'))
        ->toThrow(TelephonyException::class, 'Channel not found');
});

/*
| B2.3b-i QD-1 — the hold-music pair. A waiting caller holds on the line they are
| already on, so there is no waiting-room object to create and destroy: music simply
| starts and stops on their own channel. No music class is named, so Asterisk plays
| the channel's own configured one (the stock `default` set verified on the server).
*/

it('starts hold music on the waiting caller\'s own leg (QD-1)', function () {
    Http::fake(['*' => Http::response([])]);

    $this->telephony->startHoldMusic('caller-leg');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://voice.test:8088/ari/channels/caller-leg/moh');
});

it('stops the hold music before the caller\'s call connects (QD-1)', function () {
    Http::fake(['*' => Http::response([])]);

    $this->telephony->stopHoldMusic('caller-leg');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'http://voice.test:8088/ari/channels/caller-leg/moh');
});

/*
| inbound-audio slice 4 — the playback pair. Endpoints and parameter names verified
| against the Asterisk 20 branch's own API spec (rest-api/api-docs/channels.json and
| playbacks.json), not against tutorials: POST /channels/{id}/play takes `media` as a
| query value and answers with a Playback object; DELETE /playbacks/{id} stops one.
*/

it('plays a sound on the caller\'s own leg and hands back the play\'s id', function () {
    Http::fake(['*' => Http::response(['id' => 'playback-7', 'state' => 'playing'])]);

    $playbackId = $this->telephony->play('caller-leg', ['https://hcmis.test/closed-message/1/abc.wav']);

    expect($playbackId)->toBe('playback-7');

    // `media` rides the QUERY STRING, which is what the 20 branch's spec marks it as,
    // and the address carries the `sound:` scheme — see the test below for why.
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://voice.test:8088/ari/channels/caller-leg/play?'
            .http_build_query(['media' => 'sound:https://hcmis.test/closed-message/1/abc.wav']));
});

it('joins several sounds into ONE comma-separated media value, never a repeated one', function () {
    // 🔴 S165 measured this on the box: Asterisk splits `media` on commas into the play's
    // list, and sending the parameter twice keeps only the LAST value. Slice 6 times its
    // menu wait by appending a silent file this way, so getting it wrong would silently
    // drop the greeting and play only the silence.
    Http::fake(['*' => Http::response(['id' => 'playback-7'])]);

    $this->telephony->play('caller-leg', ['https://hcmis.test/one.wav', 'https://hcmis.test/two.wav']);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://voice.test:8088/ari/channels/caller-leg/play?'
        .http_build_query(['media' => 'sound:https://hcmis.test/one.wav,sound:https://hcmis.test/two.wav']));
});

it('🔴 prefixes every address with the scheme, or the caller hears silence', function () {
    // THE S167 STAGING BUG, pinned. res_stasis_playback.c matches the media value
    // against six known schemes and sends anything else to a branch that logs "scheme
    // is unsupported" and skips it — WITHOUT failing the play. So the call answers,
    // the play reports success, the finish event arrives, we hang up on cue, and the
    // caller hears nothing at all. The voice box never even requests the file, which is
    // how it was caught: the web log showed no fetch from the box.
    Http::fake(['*' => Http::response(['id' => 'playback-7'])]);

    $this->telephony->play('caller-leg', ['https://hcmis.test/closed-message/1/abc.wav']);

    Http::assertSent(function (Request $request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_starts_with((string) ($query['media'] ?? ''), 'sound:https://');
    });
});

it('stops a play that is still running', function () {
    Http::fake(['*' => Http::response([])]);

    $this->telephony->stopPlayback('playback-7');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'http://voice.test:8088/ari/playbacks/playback-7');
});
