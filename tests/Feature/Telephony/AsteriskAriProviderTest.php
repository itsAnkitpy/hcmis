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
