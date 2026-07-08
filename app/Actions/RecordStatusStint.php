<?php

namespace App\Actions;

use App\Enums\PresenceStatus;
use App\Enums\StintEndedVia;
use App\Models\AgentPresence;
use App\Models\AgentStatusHistory;
use App\Models\BreakCategory;
use Illuminate\Support\Carbon;

/**
 * The history half of the single door (BK-2): called by AgentConsole::setPresence()
 * — and nowhere else — inside the same transaction as the board upsert, so the
 * board and its memory can never disagree. Closes the agent's open stint and opens
 * the next one; repeating the SAME status in a live session is a no-op (no
 * duplicate stints).
 *
 * The BK-6 lazy close lives here: when the open stint belongs to a session that
 * went stale (heartbeat quiet past the window), it is closed retroactively at
 * "last heartbeat + the stale window", marked Stale — and a fresh stint opens even
 * for the same status, because a new login is a new stay, never a continuation of
 * a dead one. Staleness is judged on the board row AS IT STOOD BEFORE this write
 * ($previous), since the write itself re-stamps the heartbeat.
 *
 * Offline opens NO stint: in history, offline is the gap between stints — a stint
 * says "the agent was doing X", and rows spanning every night would be noise for
 * the duration sums this table exists to answer.
 *
 * The router's ring-time reservation (AgentRouter) deliberately bypasses this:
 * its board write is a routing lock, not a statement of what the agent is doing —
 * the screen reports the real transition through the door when the agent answers.
 */
class RecordStatusStint
{
    /**
     * @param  int  $userId  the agent (auth, never browser-supplied)
     * @param  PresenceStatus  $status  the status just written to the board
     * @param  BreakCategory|null  $category  resolved + active break type (break stints only)
     * @param  AgentPresence|null  $previous  the board row BEFORE this write (staleness evidence)
     */
    public static function run(int $userId, PresenceStatus $status, ?BreakCategory $category, ?AgentPresence $previous): void
    {
        $open = AgentStatusHistory::query()
            ->open()
            ->where('user_id', $userId)
            ->latest('started_at')
            ->first();

        if ($open !== null) {
            // No board row before the write = nothing vouches for the session — treat as dead.
            $sessionWentStale = $previous === null || $previous->isStale();

            if (! $sessionWentStale && $open->status === $status) {
                return; // a live session repeating its status — no duplicate stint
            }

            $open->update([
                'ended_at' => $sessionWentStale ? self::staleCutoff($open, $previous) : now(),
                'ended_via' => $sessionWentStale ? StintEndedVia::Stale : StintEndedVia::Changed,
            ]);
        }

        if ($status === PresenceStatus::Offline) {
            return; // offline is the gap between stints, not a stint
        }

        AgentStatusHistory::create([
            'user_id' => $userId,
            'status' => $status,
            'break_category_id' => $status === PresenceStatus::OnBreak ? $category?->id : null,
            'limit_minutes' => $status === PresenceStatus::OnBreak ? $category?->time_limit_minutes : null,
            'started_at' => now(),
        ]);
    }

    /**
     * When a dead session's stint effectively ended (BK-6): the last heartbeat
     * plus the stale window — the same arithmetic every reader applies, so the
     * physical close never changes a number a reader already showed. Floored at
     * the stint's own start so the close can never predate the open.
     */
    private static function staleCutoff(AgentStatusHistory $open, ?AgentPresence $previous): Carbon
    {
        $staleAfter = (int) config('telephony.presence.stale_after_seconds');

        $cutoff = $previous?->last_seen_at?->copy()->addSeconds($staleAfter);

        if ($cutoff === null || $cutoff->lt($open->started_at)) {
            return $open->started_at->copy();
        }

        return $cutoff;
    }
}
