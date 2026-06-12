<?php

declare(strict_types=1);

namespace App\Telephony;

use RuntimeException;

/**
 * Any failure talking to the voice engine — a refused command, an unreachable
 * host, a broken handshake. Keeps engine/HTTP details out of the app's catch
 * blocks: callers catch this, not vendor exceptions.
 */
class TelephonyException extends RuntimeException {}
