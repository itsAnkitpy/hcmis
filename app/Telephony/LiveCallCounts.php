<?php

declare(strict_types=1);

namespace App\Telephony;

use Illuminate\Support\Facades\Cache;

/**
 * The pigeonhole for the three live call numbers (Call Stats CS-2/CS-3).
 *
 * The listener and the website are two separate programs — they share a database, not
 * memory — so the listener leaves a little note where the website can pick it up, and
 * this is the one place that knows what the notes are called and how long they live.
 * Two programs must not be left to agree on three strings by hand.
 *
 * The cache rather than a new table, and the deciding reason is not convenience: a cache
 * note expires by itself. A listener that dies leaves numbers that fade out within
 * twenty seconds instead of a screen that shows "3 waiting" for the rest of the shift —
 * the same work-it-out-when-somebody-looks rule the agent board already lives by. Our
 * cache is backed by the database already, so this needs no migration and no new
 * dependency.
 *
 * Two kinds of note:
 *  - one per client, holding that client's three counts;
 *  - one saying the listener is alive, rewritten whether or not anything is happening.
 *
 * The second is not optional. Without it "the phones are quiet" and "this screen stopped
 * being told anything twenty minutes ago" look identical — both show zero — and only the
 * second is the one somebody needs to act on.
 */
class LiveCallCounts
{
    /**
     * How long a note lives. Four missed publishes at the listener's five-second beat:
     * long enough that a slow pass never blanks the board, short enough that a dead
     * listener is admitted quickly (CS-2).
     */
    public const LIFETIME_SECONDS = 20;

    /** One note per client — a leader reads the key with their own client's id in it. */
    private const COUNTS_KEY = 'telephony:live-calls:';

    /** The "somebody is still watching the phones" note (CS-3). */
    private const ALIVE_KEY = 'telephony:listener-alive';

    /**
     * The clients written on the previous publish, so a floor that has gone quiet can be
     * told so — see publish(). Held on this object because the listener keeps one of
     * these for the life of the process; a reader builds its own and never uses this.
     *
     * @var array<int, int>
     */
    private array $lastPublishedTenantIds = [];

    /**
     * Leave the notes: this is the whole picture of what the phones are doing right now.
     * The aliveness note is written every time, including when there is not a single call
     * anywhere — that is the whole point of it.
     *
     * 🔴 A CLIENT WHOSE LAST CALL ENDS HAS TO BE TOLD SO. What comes in here is only the
     * clients that HAVE live calls, so the moment a floor goes quiet it simply stops being
     * mentioned — and its last note would sit in the pigeonhole still saying "3 on a call"
     * until it expired twenty seconds later. So anyone who was in the previous picture and
     * is not in this one gets an explicit all-zero note. It settles by itself: what we
     * remember is what the CALLER passed in, never the zeros we added, so a quiet floor is
     * written once and then left alone.
     *
     * @param  array<int, array{active: int, ringing: int, waiting: int, oldestWaitingAt?: int|null}>  $countsByTenant
     */
    public function publish(array $countsByTenant): void
    {
        $liveTenantIds = array_keys($countsByTenant);

        foreach (array_diff($this->lastPublishedTenantIds, $liveTenantIds) as $goneQuietTenantId) {
            $countsByTenant[$goneQuietTenantId] = ['active' => 0, 'ringing' => 0, 'waiting' => 0, 'oldestWaitingAt' => null];
        }

        $this->lastPublishedTenantIds = $liveTenantIds;

        foreach ($countsByTenant as $tenantId => $counts) {
            Cache::put(self::COUNTS_KEY.$tenantId, $counts, self::LIFETIME_SECONDS);
        }

        Cache::put(self::ALIVE_KEY, now()->getTimestamp(), self::LIFETIME_SECONDS);
    }

    /**
     * Read the given clients' notes and add them up — one client for a team leader, every
     * client for our own global staff (CS-3). Asked for BY NAME because there is no way to
     * ask a cache for "everything with this prefix"; the client list lives in our own
     * database, so the caller reads that first and passes the ids in.
     *
     * A client with no note (no live calls, or never any) contributes zeros.
     *
     * 🔴 THE FOURTH VALUE IS NOT ADDED, AND ADDING IT WOULD BE A REAL BUG (Longest Wait
     * LW-3). The three counts combine by adding — two clients with two calls each are four
     * calls. The longest wait does not: one floor's oldest caller holding five minutes and
     * another's holding three is a longest wait of FIVE, never eight. What is kept is the
     * EARLIEST arrival across the clients asked for, because earliest arrival is longest
     * wait — which is also why the note carries a moment rather than a duration.
     *
     * Null means nobody is holding on any of them: a floor with nothing waiting has no
     * clock to show, which is a different statement from a wait of zero seconds.
     *
     * @param  array<int, int>  $tenantIds
     * @return array{active: int, ringing: int, waiting: int, oldestWaitingAt: int|null}
     */
    public function read(array $tenantIds): array
    {
        $totals = ['active' => 0, 'ringing' => 0, 'waiting' => 0, 'oldestWaitingAt' => null];

        if ($tenantIds === []) {
            return $totals;
        }

        $notes = Cache::many(array_map(
            fn (int $tenantId): string => self::COUNTS_KEY.$tenantId,
            array_values($tenantIds),
        ));

        foreach ($notes as $counts) {
            if (! is_array($counts)) {
                continue;   // no note for that client: nothing live, or nothing yet
            }

            foreach (['active', 'ringing', 'waiting'] as $number) {
                $totals[$number] += (int) ($counts[$number] ?? 0);
            }

            $arrivedAt = $counts['oldestWaitingAt'] ?? null;

            if ($arrivedAt !== null) {
                $totals['oldestWaitingAt'] = $totals['oldestWaitingAt'] === null
                    ? (int) $arrivedAt
                    : min($totals['oldestWaitingAt'], (int) $arrivedAt);
            }
        }

        return $totals;
    }

    /**
     * Is the phone service still reporting? When this is false the board must say so
     * rather than show three zeros — a calm floor and a dead listener look identical
     * otherwise, and only one of them needs somebody to do something (CS-7).
     */
    public function isReporting(): bool
    {
        return Cache::has(self::ALIVE_KEY);
    }
}
