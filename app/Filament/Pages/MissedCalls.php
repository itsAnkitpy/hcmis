<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\CallDirection;
use App\Enums\CallOutcome;
use App\Enums\RoleName;
use App\Models\Call;
use App\Models\User;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * Missed calls (B2.3b-i QD-9) — the people who rang and never got through, for an
 * agent to work back. Without this screen the records exist but are reachable only
 * through the database, which is the same as not existing (the ND-5 precedent: the
 * row is where it writes, the screen is the thing).
 *
 * Two ways onto this list, and the difference is the point (QD-6): the caller gave
 * up while holding, or we stopped waiting on their behalf when the client's maximum
 * hold time ran out. Same experience for them; opposite meaning for whoever runs the
 * floor — one says the hold is too long, the other says the cap is too short.
 *
 * NEWEST FIRST, deliberately (the Vicidial finding): someone who rang minutes ago and
 * gave up is still interested right now, so an oldest-first list would be the wrong
 * instrument entirely.
 *
 * "Never got through" is read off the row, not a flag: an inbound call, an abandoned
 * or unanswered outcome, and NO agent on it. An inbound call an agent handled and
 * dispositioned as a non-contact also carries `no_answer`, and it carries their id —
 * which is exactly what keeps it off this list.
 *
 * Gate: agents (this is their work list) plus the same audience that can read Call
 * Review, so a team leader can see the client's missed calls too. The client wall is
 * inherited from the request context like every other screen — no new gate.
 *
 * No call-back button in v1 (QD-9): ringing the number from here means reaching into
 * the agent console's dial path, a bigger seam than the list itself. The number is
 * shown; the agent dials it in the console.
 */
class MissedCalls extends Page
{
    /** How many rows the list shows — newest first, worked from the top. */
    private const SHOW_LATEST = 100;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoneXMark;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Missed Calls';

    protected static ?string $title = 'Missed Calls';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.missed-calls';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        return $user->hasRole(RoleName::Agent->value) || Gate::allows('viewAny', Call::class);
    }

    public function getSubheading(): ?string
    {
        return 'Callers who never got through — newest first, so the freshest one is at the top.';
    }

    /**
     * The callers nobody reached, newest first.
     *
     * @return Collection<int, Call>
     */
    public function missedCalls(): Collection
    {
        return Call::query()
            ->where('direction', CallDirection::Inbound)
            ->whereNull('agent_id')
            ->whereIn('outcome', [CallOutcome::Abandoned->value, CallOutcome::NoAnswer->value])
            ->orderByDesc('created_at')
            ->limit(self::SHOW_LATEST)
            ->get();
    }

    /**
     * How long this caller was on the line before they were lost (S88 review #6).
     *
     * The number on the screen is unchanged; only its source is (CT-4). It used to read
     * the stored `duration_seconds`, which was also labelled "Duration" on the Calls list
     * — one field carrying two different meanings, invisible only because nothing but a
     * missed call ever filled it. It is now computed from the two moments, like every
     * other figure. The hour-aware formatting moved onto the model with it.
     */
    public function waitedFor(Call $call): string
    {
        return Call::asClock($call->waitedSeconds());
    }

    /** Plain words for how a caller ended up on this list (QD-6). */
    public function reasonFor(Call $call): string
    {
        return $call->outcome === CallOutcome::Abandoned
            ? 'They gave up waiting'
            : 'We stopped waiting';
    }
}
