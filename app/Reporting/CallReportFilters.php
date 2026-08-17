<?php

declare(strict_types=1);

namespace App\Reporting;

use App\Enums\CallDirection;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/**
 * The parsed, trusted shape of a report's filter form (RP-1/RP-5). One immutable
 * value object the counting layer (CallReportService) and the CSV export both take,
 * so the date range + optional agent / campaign / direction narrowing is defined in
 * exactly one place.
 *
 * WHY guard-parse (RP-6 build note): the dashboard + report filter forms expose
 * their state as UNVALIDATED live input (Filament flags `$pageFilters` / `$filters`
 * as such — it is read straight off the wire). So fromArray() parses defensively —
 * a garbage date becomes null (no filter), a non-numeric id becomes null, an unknown
 * direction becomes null — and can never throw or reach the query as raw input. The
 * tenant wall (RLS) sits BELOW this and is untouched by whatever the browser sends.
 *
 * The date range filters on `created_at` (the one indexed column — RP-1 build note),
 * so `from` snaps to the start of its day and `to` to the end, giving inclusive
 * day-bucket ranges that ride `calls_tenant_id_created_at_index`.
 *
 * 🔴 THE DAY IS CUT IN THE CLIENT'S OWN ZONE (S118, CE-10a). "13 August" on an India
 * floor starts at 18:30 UTC on the 12th. The Call Export has read dates this way since
 * S108; before S118 these reports read them on the application clock, so the same
 * chosen day named two different sets of calls on two screens.
 */
final class CallReportFilters
{
    public readonly ?Carbon $from;

    public readonly ?Carbon $to;

    /**
     * 🔴 THE BOUNDARIES ARE STORED AS UTC INSTANTS, whatever zone the caller built
     * them in, and the guard sits HERE rather than at each call site (S118).
     *
     * Laravel turns a date object into text for the query IN THE ZONE THAT OBJECT
     * CARRIES. So an India-midnight boundary reaches Postgres as the text
     * `00:00:00` and silently reads five and a half hours of the wrong day —
     * a fault no green test run shows unless the test itself picks a real zone.
     * One conversion in the shared constructor cannot be forgotten by a caller.
     */
    public function __construct(
        ?Carbon $from = null,
        ?Carbon $to = null,
        public readonly ?int $agentId = null,
        public readonly ?int $campaignId = null,
        public readonly ?CallDirection $direction = null,
        public readonly ?int $clientId = null,
    ) {
        $this->from = $from?->copy()->utc();
        $this->to = $to?->copy()->utc();
    }

    /**
     * The client's own today, at their own midnight (CE-10a) — the fallback every
     * "no date picked" screen reads, and the anchor the dashboard's today/yesterday
     * widget counts back from.
     *
     * Returned IN THE CLIENT'S ZONE, so `->endOfDay()` and `->subDay()` mean what the
     * floor means by them. The constructor above converts to UTC on the way into a
     * query, so no caller has to remember to.
     */
    public static function clientToday(): Carbon
    {
        return Carbon::now(TenantContext::reportTimezone())->startOfDay();
    }

    /**
     * One picked date turned into the UTC instant a query compares against: the start of
     * that day on the client's clock, optionally plus whole days (CE-10a).
     *
     * For the Filament tables that hold their own date filter rather than taking this
     * object — the Calls list and the audit log. Both used `whereDate`, which compares
     * the raw stored date and so named a different set of rows from the reports and the
     * export for the same chosen day.
     *
     * The extra days are added IN THE CLIENT'S ZONE, before the conversion, so a zone
     * with daylight saving does not land an hour out on the two days a year it changes.
     * The Call Export keeps its own copy of this line at `CallExportRows::date()`, where
     * the zone is resolved once for a fifty-thousand-row stream.
     *
     * A table filter's state is unvalidated live input like the report forms, so an
     * unparseable value drops the bound rather than throwing (the fromArray posture).
     */
    public static function clientDayStart(mixed $date, int $addDays = 0): ?Carbon
    {
        if (blank($date)) {
            return null;
        }

        return rescue(
            fn (): Carbon => Carbon::parse((string) $date, TenantContext::reportTimezone())
                ->startOfDay()
                ->addDays($addDays)
                ->utc(),
            null,
            report: false,
        );
    }

    /**
     * Build from a raw filter-form array (the Filament `filters` / `pageFilters`
     * state). Every field is optional and defensively parsed. Keys mirror the
     * filter-form field names: startDate, endDate, agentId, campaignId, direction,
     * clientId.
     *
     * `clientId` is the global-staff narrowing (RP-4 seam, brought forward S63): a
     * global HC user who runs cross-tenant can pick ONE client to scope the rollup
     * to, or leave it blank for all clients. It only narrows WITHIN what the wall
     * already permits — a per-client user is pinned to their own client by the
     * TenantScope + RLS regardless of what clientId the browser sends (an injected
     * other-client id simply yields an empty AND, never another client's rows).
     *
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        // CE-10a. The picked date is a DAY IN THE CLIENT'S ZONE, not ours — the same
        // line the Call Export runs at CallExportRows::date().
        $zone = TenantContext::reportTimezone();

        return new self(
            from: self::parseDate($raw['startDate'] ?? null, $zone)?->startOfDay(),
            to: self::parseDate($raw['endDate'] ?? null, $zone)?->endOfDay(),
            agentId: self::parseId($raw['agentId'] ?? null),
            campaignId: self::parseId($raw['campaignId'] ?? null),
            direction: self::parseDirection($raw['direction'] ?? null),
            clientId: self::parseId($raw['clientId'] ?? null),
        );
    }

    /**
     * Parse a browser-supplied date string, swallowing anything unparseable to null
     * (the rescue pattern the wrap-up validator uses) — a bad date simply drops the
     * bound rather than 500-ing the report.
     */
    private static function parseDate(mixed $value, string $zone): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return rescue(fn (): Carbon => Carbon::parse($value, $zone), null, report: false);
    }

    /**
     * A positive integer id, or null. Rejects 0, negatives and non-numerics so a
     * junk value never lands as a WHERE that quietly matches nothing-but-looks-set.
     */
    private static function parseId(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    private static function parseDirection(mixed $value): ?CallDirection
    {
        return is_string($value) ? CallDirection::tryFrom($value) : null;
    }
}
