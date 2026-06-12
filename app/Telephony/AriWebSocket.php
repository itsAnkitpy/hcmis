<?php

declare(strict_types=1);

namespace App\Telephony;

/**
 * The event pipe: one long-lived WebSocket where Asterisk streams JSON events
 * (connecting it is also what registers our Stasis app — no pipe, no calls).
 *
 * Hand-rolled on purpose (B1 D2, locked): WebSocket framing is frozen
 * (RFC 6455, 2011) and the PHP ARI-client ecosystem is dead, so ~150 lines we
 * own beat a dependency we'd babysit. Hardened beyond the lab script with the
 * lock's exact list: read timeout, continuation-frame assembly, close-frame
 * reply, liveness pings — the reconnect loop lives in telephony:listen. If
 * this class ever causes a production incident or burns more than a day of
 * debugging, B1's reopen trigger applies: put phrity/websocket behind this
 * same surface (a half-day swap, not a redesign).
 *
 * The frame layer (ingest / nextMessage) never touches the network, so tests
 * feed it raw bytes; only connect / readEvent / ping / close use the socket.
 */
class AriWebSocket
{
    private const OPCODE_CONTINUATION = 0x0;

    private const OPCODE_TEXT = 0x1;

    private const OPCODE_CLOSE = 0x8;

    private const OPCODE_PING = 0x9;

    private const OPCODE_PONG = 0xA;

    /** Anything bigger than this is not an ARI event — treat the stream as broken. */
    private const MAX_MESSAGE_BYTES = 10 * 1024 * 1024;

    /** @var resource|null */
    private $socket;

    private string $buffer = '';

    private float $lastFrameAt = 0.0;

    private bool $assemblingFragments = false;

    private int $fragmentOpcode = self::OPCODE_CONTINUATION;

    private string $fragmentPayload = '';

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly string $app,
    ) {}

    /**
     * Open the pipe: plain HTTP Upgrade handshake, then frames. Throws
     * AriConnectionLost when the host is unreachable, TelephonyException
     * when ARI refuses us (bad credentials / app name).
     */
    public function connect(float $timeoutSeconds = 10.0): void
    {
        $socket = @stream_socket_client(
            "tcp://{$this->host}:{$this->port}", $errorCode, $errorMessage, $timeoutSeconds,
        );

        if ($socket === false) {
            throw new AriConnectionLost("Cannot reach ARI at {$this->host}:{$this->port} — {$errorMessage}");
        }

        $query = http_build_query(['api_key' => "{$this->username}:{$this->password}", 'app' => $this->app]);
        $key = base64_encode(random_bytes(16));

        fwrite($socket,
            "GET /ari/events?{$query} HTTP/1.1\r\n"
            ."Host: {$this->host}:{$this->port}\r\n"
            ."Upgrade: websocket\r\n"
            ."Connection: Upgrade\r\n"
            ."Sec-WebSocket-Key: {$key}\r\n"
            ."Sec-WebSocket-Version: 13\r\n\r\n");

        $raw = '';

        while (! str_contains($raw, "\r\n\r\n")) {
            $chunk = fread($socket, 1024);

            if ($chunk === false || $chunk === '') {
                fclose($socket);

                throw new AriConnectionLost('WebSocket handshake: connection closed early');
            }

            $raw .= $chunk;
        }

        // Bytes past the handshake headers may already be frame data — keep them.
        [$head, $alreadyBuffered] = explode("\r\n\r\n", $raw, 2);

        if (! str_contains($head, ' 101 ')) {
            fclose($socket);

            throw new TelephonyException("ARI refused the WebSocket handshake (credentials or app name?):\n{$head}");
        }

        stream_set_blocking($socket, false);    // from here on, readEvent() paces the reads

        $this->socket = $socket;
        $this->buffer = $alreadyBuffered;
        $this->assemblingFragments = false;
        $this->fragmentPayload = '';
        $this->lastFrameAt = microtime(true);
    }

    /**
     * The next JSON event, or null when the window closes with none. Replies
     * to pings, swallows pongs, honours close frames — callers only ever see
     * events. Throws AriConnectionLost when the pipe is gone.
     *
     * @return array<string, mixed>|null
     */
    public function readEvent(float $timeoutSeconds): ?array
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (true) {
            while (($message = $this->nextMessage()) !== null) {
                [$opcode, $payload] = $message;

                if ($opcode === self::OPCODE_TEXT) {
                    $event = json_decode($payload, true);

                    if (is_array($event)) {
                        return $event;
                    }

                    continue;   // not JSON — nothing an event consumer can use
                }

                if ($opcode === self::OPCODE_PING) {
                    $this->send(self::OPCODE_PONG, $payload);

                    continue;
                }

                if ($opcode === self::OPCODE_CLOSE) {
                    $this->send(self::OPCODE_CLOSE);
                    $this->disconnect();

                    throw new AriConnectionLost('Asterisk closed the event stream');
                }

                // Pongs (liveness answers) and binary frames carry no events.
            }

            $remaining = $deadline - microtime(true);

            if ($remaining <= 0) {
                return null;
            }

            $this->fill($remaining);
        }
    }

    /** Ask the far end for a sign of life; any reply resets the idle clock. */
    public function ping(): void
    {
        $this->send(self::OPCODE_PING, 'hcmis');
    }

    public function secondsSinceLastFrame(): float
    {
        return microtime(true) - $this->lastFrameAt;
    }

    public function close(): void
    {
        if ($this->socket === null) {
            return;
        }

        try {
            $this->send(self::OPCODE_CLOSE);
        } catch (AriConnectionLost) {
            // Closing a pipe that is already gone is fine.
        }

        $this->disconnect();
    }

    /** Feed raw bytes into the frame buffer (no socket involved — tests call this directly). */
    public function ingest(string $bytes): void
    {
        $this->buffer .= $bytes;
    }

    /**
     * Peel the next complete MESSAGE off the buffer: a whole data message
     * (continuation fragments assembled — the case the lab script punted on)
     * or a control frame, which RFC 6455 allows to interleave mid-fragment.
     * Null = need more bytes.
     *
     * @return array{0: int, 1: string}|null opcode + payload
     */
    public function nextMessage(): ?array
    {
        while (($frame = $this->parseFrame()) !== null) {
            [$final, $opcode, $payload] = $frame;

            // Control frames (close/ping/pong) are never fragmented and may
            // arrive between fragments of a data message — hand them straight up.
            if ($opcode >= self::OPCODE_CLOSE) {
                return [$opcode, $payload];
            }

            if ($opcode !== self::OPCODE_CONTINUATION) {
                if ($final) {
                    return [$opcode, $payload];
                }

                // First fragment of a split message — start assembling.
                $this->assemblingFragments = true;
                $this->fragmentOpcode = $opcode;
                $this->fragmentPayload = $payload;

                continue;
            }

            if (! $this->assemblingFragments) {
                throw new TelephonyException('WebSocket protocol error: continuation frame with nothing to continue');
            }

            $this->fragmentPayload .= $payload;

            if (strlen($this->fragmentPayload) > self::MAX_MESSAGE_BYTES) {
                throw new TelephonyException('WebSocket message exceeded '.self::MAX_MESSAGE_BYTES.' bytes');
            }

            if ($final) {
                $this->assemblingFragments = false;
                $payload = $this->fragmentPayload;
                $this->fragmentPayload = '';

                return [$this->fragmentOpcode, $payload];
            }
        }

        return null;
    }

    /**
     * One raw frame off the buffer: [final?, opcode, payload]. Null = partial
     * frame, wait for more bytes. Server frames are never masked (RFC 6455),
     * so there is no unmask step.
     *
     * @return array{0: bool, 1: int, 2: string}|null
     */
    private function parseFrame(): ?array
    {
        if (strlen($this->buffer) < 2) {
            return null;
        }

        $byte0 = ord($this->buffer[0]);
        $final = ($byte0 & 0x80) !== 0;
        $opcode = $byte0 & 0x0F;

        $length = ord($this->buffer[1]) & 0x7F;
        $headerBytes = 2;

        if ($length === 126) {
            if (strlen($this->buffer) < 4) {
                return null;
            }

            $length = unpack('n', substr($this->buffer, 2, 2))[1];
            $headerBytes = 4;
        } elseif ($length === 127) {
            if (strlen($this->buffer) < 10) {
                return null;
            }

            $length = unpack('J', substr($this->buffer, 2, 8))[1];
            $headerBytes = 10;
        }

        if ($length > self::MAX_MESSAGE_BYTES) {
            throw new TelephonyException("WebSocket frame declared {$length} bytes — not an ARI event stream");
        }

        if (strlen($this->buffer) < $headerBytes + $length) {
            return null;
        }

        $payload = substr($this->buffer, $headerBytes, $length);
        $this->buffer = substr($this->buffer, $headerBytes + $length);
        $this->lastFrameAt = microtime(true);

        return [$final, $opcode, $payload];
    }

    /** Pull whatever the socket has (waiting up to the timeout) into the buffer. */
    private function fill(float $timeoutSeconds): void
    {
        if ($this->socket === null) {
            throw new AriConnectionLost('Event pipe is not connected');
        }

        $read = [$this->socket];
        $write = $except = [];
        $seconds = (int) $timeoutSeconds;
        $microseconds = (int) (fmod($timeoutSeconds, 1.0) * 1_000_000);

        if (stream_select($read, $write, $except, $seconds, $microseconds) > 0) {
            $chunk = fread($this->socket, 65536);

            if (($chunk === false || $chunk === '') && feof($this->socket)) {
                $this->disconnect();

                throw new AriConnectionLost('Event stream dropped (connection reset)');
            }

            $this->ingest((string) $chunk);
        }
    }

    /** Client frames must be masked (RFC 6455) — server frames arrive unmasked. */
    private function send(int $opcode, string $payload = ''): void
    {
        if ($this->socket === null) {
            throw new AriConnectionLost('Event pipe is not connected');
        }

        $length = strlen($payload);
        $header = chr(0x80 | $opcode);

        if ($length < 126) {
            $header .= chr(0x80 | $length);
        } elseif ($length < 65536) {
            $header .= chr(0x80 | 126).pack('n', $length);
        } else {
            $header .= chr(0x80 | 127).pack('J', $length);
        }

        $mask = random_bytes(4);
        $masked = $length === 0 ? '' : $payload ^ substr(str_repeat($mask, intdiv($length, 4) + 1), 0, $length);

        fwrite($this->socket, $header.$mask.$masked);
    }

    private function disconnect(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }

        $this->socket = null;
    }
}
