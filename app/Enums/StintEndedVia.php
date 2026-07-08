<?php

namespace App\Enums;

/**
 * How a status stint was closed (BK-2/BK-6). `Changed` is the normal path: the
 * agent's next status change closed it. `Stale` is the lazy close: the session
 * died mid-stint (tab crash, network drop) and the next write through the door
 * closed it retroactively at "last heartbeat + the stale window" — the same
 * truth-at-read-time rule the live board already applies (no scheduler, BK-6).
 */
enum StintEndedVia: string
{
    case Changed = 'changed';
    case Stale = 'stale';
}
