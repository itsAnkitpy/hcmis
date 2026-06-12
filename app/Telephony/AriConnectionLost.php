<?php

declare(strict_types=1);

namespace App\Telephony;

/**
 * The event pipe (WebSocket) dropped or went silent. Split from the base
 * exception because the listener treats it differently: a lost connection
 * means "reconnect with backoff", while a refused command means "abort this
 * call and keep listening".
 */
class AriConnectionLost extends TelephonyException {}
