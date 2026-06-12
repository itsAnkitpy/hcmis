<?php

declare(strict_types=1);

namespace App\Events\Telephony;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A call leg went live (the engine confirmed it, not just our request to
 * answer — commands are requests, events are facts).
 */
class CallAnswered
{
    use Dispatchable;

    public function __construct(
        public string $callId,
    ) {}
}
