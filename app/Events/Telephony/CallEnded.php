<?php

declare(strict_types=1);

namespace App\Events\Telephony;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A call leg is gone — hung up by either side or torn down by the engine.
 */
class CallEnded
{
    use Dispatchable;

    public function __construct(
        public string $callId,
    ) {}
}
