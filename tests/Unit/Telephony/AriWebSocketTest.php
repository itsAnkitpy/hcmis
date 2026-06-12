<?php

use App\Telephony\AriWebSocket;
use App\Telephony\TelephonyException;

/**
 * The frame layer is socket-free on purpose: these tests feed raw RFC 6455
 * bytes straight into the buffer and read assembled messages back — the
 * hardening cases (split frames, interleaved pings, runaway sizes) the lab
 * script deliberately skipped.
 */
function ariPipe(): AriWebSocket
{
    return new AriWebSocket('127.0.0.1', 8088, 'user', 'pass', 'test-app');
}

/** Build one server→client frame (server frames are never masked). */
function ariFrame(int $opcode, string $payload, bool $final = true): string
{
    $header = chr(($final ? 0x80 : 0x00) | $opcode);
    $length = strlen($payload);

    if ($length < 126) {
        $header .= chr($length);
    } elseif ($length < 65536) {
        $header .= chr(126).pack('n', $length);
    } else {
        $header .= chr(127).pack('J', $length);
    }

    return $header.$payload;
}

it('parses a single text frame into one message', function () {
    $pipe = ariPipe();
    $pipe->ingest(ariFrame(0x1, '{"type":"StasisStart"}'));

    expect($pipe->nextMessage())->toBe([0x1, '{"type":"StasisStart"}'])
        ->and($pipe->nextMessage())->toBeNull();
});

it('waits for more bytes when a frame arrives split', function () {
    $frame = ariFrame(0x1, '{"type":"ChannelStateChange"}');
    $pipe = ariPipe();

    $pipe->ingest(substr($frame, 0, 7));
    expect($pipe->nextMessage())->toBeNull();

    $pipe->ingest(substr($frame, 7));
    expect($pipe->nextMessage())->toBe([0x1, '{"type":"ChannelStateChange"}']);
});

it('parses extended payload lengths (16-bit and 64-bit)', function (int $size) {
    $payload = str_repeat('a', $size);
    $pipe = ariPipe();
    $pipe->ingest(ariFrame(0x1, $payload));

    expect($pipe->nextMessage())->toBe([0x1, $payload]);
})->with([
    '16-bit length' => 300,
    '64-bit length' => 70_000,
]);

it('returns several messages from one batch of bytes, in order', function () {
    $pipe = ariPipe();
    $pipe->ingest(ariFrame(0x1, 'first').ariFrame(0x1, 'second'));

    expect($pipe->nextMessage())->toBe([0x1, 'first'])
        ->and($pipe->nextMessage())->toBe([0x1, 'second'])
        ->and($pipe->nextMessage())->toBeNull();
});

it('assembles a message split across continuation frames', function () {
    $pipe = ariPipe();
    $pipe->ingest(ariFrame(0x1, '{"type":', final: false));
    $pipe->ingest(ariFrame(0x0, '"Giant', final: false));
    $pipe->ingest(ariFrame(0x0, 'Event"}', final: true));

    expect($pipe->nextMessage())->toBe([0x1, '{"type":"GiantEvent"}']);
});

it('hands control frames up even mid-fragment', function () {
    $pipe = ariPipe();
    $pipe->ingest(ariFrame(0x1, 'part-one', final: false));
    $pipe->ingest(ariFrame(0x9, 'ping-payload'));            // ping interleaves the fragments
    $pipe->ingest(ariFrame(0x0, '-part-two', final: true));

    expect($pipe->nextMessage())->toBe([0x9, 'ping-payload'])
        ->and($pipe->nextMessage())->toBe([0x1, 'part-one-part-two']);
});

it('rejects a continuation frame with nothing to continue', function () {
    $pipe = ariPipe();
    $pipe->ingest(ariFrame(0x0, 'orphan', final: true));

    expect(fn () => $pipe->nextMessage())->toThrow(TelephonyException::class);
});

it('rejects a frame declaring an absurd length before buffering it', function () {
    // Header claims 20 MB; no payload bytes follow. The guard must fire on
    // the declared size, not wait to buffer 20 MB first.
    $header = chr(0x81).chr(127).pack('J', 20 * 1024 * 1024);

    $pipe = ariPipe();
    $pipe->ingest($header);

    expect(fn () => $pipe->nextMessage())->toThrow(TelephonyException::class);
});

it('parses a close frame as a message', function () {
    $pipe = ariPipe();
    $pipe->ingest(ariFrame(0x8, ''));

    expect($pipe->nextMessage())->toBe([0x8, '']);
});
