<?php

declare(strict_types=1);

namespace App\Events\Telephony;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An outside call arrived and is waiting to be answered. Dispatched by the
 * telephony:listen translation layer (B1 D3) — the app hears call-language
 * events, never raw engine payloads.
 */
class CallRinging
{
    use Dispatchable;

    public function __construct(
        public string $callId,
        public ?string $fromNumber = null,
    ) {}
}
