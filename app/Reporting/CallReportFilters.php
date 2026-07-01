<?php

declare(strict_types=1);

namespace App\Reporting;

use App\Enums\CallDirection;
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
 */
final class CallReportFilters
{
    public function __construct(
        public readonly ?Carbon $from = null,
        public readonly ?Carbon $to = null,
        public readonly ?int $agentId = null,
        public readonly ?int $campaignId = null,
        public readonly ?CallDirection $direction = null,
        public readonly ?int $clientId = null,
    ) {}

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
        return new self(
            from: self::parseDate($raw['startDate'] ?? null)?->startOfDay(),
            to: self::parseDate($raw['endDate'] ?? null)?->endOfDay(),
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
    private static function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return rescue(fn (): Carbon => Carbon::parse($value), null, report: false);
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
