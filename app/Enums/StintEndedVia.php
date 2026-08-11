<?php

namespace App\Enums;

/**
 * How a status stint was closed (BK-2/BK-6). `Changed` is the normal path: the
 * agent's next status change closed it. `Stale` is the lazy close: the session
 * died mid-stint (tab crash, network drop) and the next write through the door
 * closed it retroactively at "last heartbeat + the stale window" — the same
 * truth-at-read-time rule the live board already applies (no scheduler, BK-6).
 * `Forced` is slice 5's supervisor button (LB-15): a manager ended a session that
 * was still answering — the agent had plainly gone home without logging out. A
 * session that had genuinely died is closed as Stale even when the button caused
 * it, because "last heartbeat + the window" is the truer end time.
 *
 * A plain string column (create_agent_status_history_table) — no migration.
 */
enum StintEndedVia: string
{
    case Changed = 'changed';
    case Stale = 'stale';
    case Forced = 'forced';
}
