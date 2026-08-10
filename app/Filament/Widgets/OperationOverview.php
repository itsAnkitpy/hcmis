<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\PresenceStatus;
use App\Models\AgentPresence;
use App\Models\Call;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\User;
use App\Reporting\CallReportFilters;
use App\Tenancy\TenantContext;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * The counter strip at the top of the operations dashboard — how big is this
 * operation, before you read anything else: Campaigns · Leads · Users · Logged in.
 * DialShree opens its dialer dashboard with exactly this row ("Dialer Overview":
 * Users, Campaigns, Lists, In-Groups, Logged-In-Agents), so it is the cheapest
 * piece of familiarity we can buy for a team switching across.
 *
 * Two deliberate departures from their row, both because we have no equivalent
 * concept: their "Lists" (named groups of contacts inside a campaign) becomes our
 * plain Leads count, and their "In-Groups" (inbound routing groups) is dropped
 * rather than faked.
 *
 * NONE of these four read the date range, and that is the whole point of the strip:
 * it is inventory, not history. The tiles below it answer "how did the period go";
 * this row answers "how big is the operation right now". The heading says so, so a
 * manager who sets the range to last week is not left wondering why Leads did not
 * move.
 *
 * The global-staff client narrowing IS honoured (the shared clientId seam, RP-4).
 * The three tenant-owned models get it as a plain tenant_id filter on top of the
 * wall; users carry no tenant wall (a user can belong to several clients via the
 * user_tenant pivot), so membership is asked for explicitly — the AD-5 pattern.
 * Gated by canView() (RP-4), same audience as every other widget on the board.
 */
class OperationOverview extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected ?string $heading = 'Overview';

    protected ?string $description = 'The size of the operation right now — not affected by the date range above.';

    public static function canView(): bool
    {
        return Gate::allows('viewAny', Call::class);
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $clientId = CallReportFilters::fromArray($this->pageFilters ?? [])->clientId;

        return [
            Stat::make('Campaigns', $this->activeCampaigns($clientId))
                ->description('Switched on')
                ->color('primary'),
            Stat::make('Leads', $this->leads($clientId))
                ->description('On file')
                ->color('info'),
            Stat::make('Users', $this->users($clientId))
                ->description('With a login')
                ->color('gray'),
            Stat::make('Logged in', $this->loggedInAgents($clientId))
                ->description('Agents on the floor')
                ->color('success'),
        ];
    }

    /**
     * Campaigns currently switched on — the ones actually running, matching what
     * DialShree counts. A paused or finished campaign is not "the size of the
     * operation today", so `is_active` is the filter rather than a raw total.
     */
    protected function activeCampaigns(?int $clientId): int
    {
        return Campaign::query()
            ->where('is_active', true)
            ->when($clientId, fn ($query, int $id) => $query->where('tenant_id', $id))
            ->count();
    }

    /**
     * Every lead on file, at any point in the funnel. A "still to call" count would
     * be a different (also useful) number, but this tile answers "how much work is
     * loaded", which is what the strip is for.
     */
    protected function leads(?int $clientId): int
    {
        return Lead::query()
            ->when($clientId, fn ($query, int $id) => $query->where('tenant_id', $id))
            ->count();
    }

    /**
     * Everyone with a login on this client — agents, team leaders, QC, the lot. This
     * is DialShree's "Users" tile and it is deliberately NOT an agent head count: the
     * "Logged in" tile beside it counts agents only, and presenting the two as a
     * ratio would be the same mixing-two-populations-under-one-heading mistake their
     * Agent Status donut makes (S91's dashboard comparison).
     *
     * Users carry no tenant wall, so the client scope is asked for explicitly: the
     * filter's client if global staff picked one, otherwise the client this request is
     * pinned to. Global staff are pinned to none, so they fall through to the second
     * branch and get every user who belongs to at least one client — which leaves out
     * HC's own global staff, who are not part of any client's operation.
     *
     * No isCrossTenant() check: cross-tenant always means no tenant id, so it would
     * change nothing today, and if that ever stopped being true this way narrows the
     * count rather than widening it.
     */
    protected function users(?int $clientId): int
    {
        $tenantId = $clientId ?? TenantContext::id();

        return User::query()
            ->when(
                $tenantId,
                fn (Builder $query, int $id) => $query->whereHas('tenants', fn (Builder $tenants) => $tenants->whereKey($id)),
                fn (Builder $query) => $query->has('tenants'),
            )
            ->count();
    }

    /**
     * Agents on the floor right now — every board row whose EFFECTIVE status is not
     * Offline. Reading effectiveStatus() (rather than the stored column) is what makes
     * this agree with the availability tally below and with the Live Agents page: all
     * three ask the same model the same question, so a crashed tab counts as gone in
     * one place only if it counts as gone in all of them.
     *
     * ponytail: rows are fetched and filtered in PHP, not counted in SQL — the same
     * trade LiveAvailabilitySnapshot already makes, and for the same reason (the stale
     * window is a per-row judgement). Cheap at real agent counts, dozens per client.
     * If a cross-client rollup ever gets slow, the stale cutoff is a plain timestamp
     * and moves into the where clause.
     */
    protected function loggedInAgents(?int $clientId): int
    {
        return AgentPresence::query()
            ->when($clientId, fn ($query, int $id) => $query->where('tenant_id', $id))
            ->get()
            ->filter(fn (AgentPresence $presence): bool => $presence->effectiveStatus() !== PresenceStatus::Offline)
            ->count();
    }
}
