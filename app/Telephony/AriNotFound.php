<?php

declare(strict_types=1);

namespace App\Telephony;

/**
 * ARI answered "what you named is not here" (HTTP 404) — the channel or the playback has
 * already gone. On a live call the ordinary cause is the caller hanging up a moment ago,
 * which is a fact about the call rather than a fault worth tearing it down over.
 */
class AriNotFound extends TelephonyException {}
