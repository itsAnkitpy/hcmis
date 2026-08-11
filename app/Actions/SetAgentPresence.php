<?php

namespace App\Actions;

use App\Enums\PresenceStatus;
use App\Models\AgentPresence;
use App\Models\BreakCategory;
use Illuminate\Support\Facades\DB;

/**
 * The single door every status write goes through (BK-2): the board row and the
 * diary entry move inside ONE transaction, so the board and its memory can never
 * disagree. The board row is read BEFORE the upsert because the lazy stale-close
 * (BK-6) judges a dying session by its pre-write heartbeat.
 *
 * Lifted out of AgentConsole::setPresence() in slice 5 (LB-15b). That version was
 * hard-wired to auth()->id() — the person currently logged in — so a team leader
 * clearing somebody else's frozen screen could not use it, and building that button
 * on its own copy of the transaction would have created a SECOND door: two places
 * writing the board-and-diary pair, which is exactly what BK-2 exists to prevent.
 * Same lines, moved; the agent is now named rather than assumed.
 *
 * Callers must already be in the right client's tenant context — this writes
 * tenant-owned rows and stamps nothing itself.
 */
class SetAgentPresence
{
    /**
     * @param  int  $userId  whose board row — their own screen, or an agent a supervisor is clearing
     * @param  PresenceStatus  $status  the new status
     * @param  BreakCategory|null  $category  resolved + active break type (break stints only)
     * @param  bool  $forced  a supervisor ended this session rather than the agent (LB-15)
     */
    public static function run(int $userId, PresenceStatus $status, ?BreakCategory $category = null, bool $forced = false): void
    {
        DB::transaction(function () use ($userId, $status, $category, $forced): void {
            $previous = AgentPresence::query()->where('user_id', $userId)->first();

            AgentPresence::query()->updateOrCreate(
                ['user_id' => $userId],
                ['status' => $status, 'last_seen_at' => now()],
            );

            RecordStatusStint::run($userId, $status, $category, $previous, $forced);
        });
    }
}
