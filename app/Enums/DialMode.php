<?php

namespace App\Enums;

/**
 * How a campaign's calls get started (DIAL-1 DQ-5).
 *
 * `Manual` is every campaign that exists today and the default for every new
 * one: an agent presses Dial. `Progressive` hands that to the dialer built in
 * slice 3 — one customer call per free desk, reserved before the number is
 * dialled (DQ-3), which is why there is no ratio setting to go with it.
 *
 * NB: DialShree calls its ratio dialer "Progressive" too, and means something
 * else by it — lines dialled per active agent, which is the thing DQ-3 rules
 * out. Ours is 1:1 by construction.
 */
enum DialMode: string
{
    case Manual = 'manual';
    case Progressive = 'progressive';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual — agents press Dial',
            self::Progressive => 'Progressive — the dialer calls for them',
        };
    }
}
