<?php

namespace App\Audit;

use App\Models\User;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Support\ActivityLogger;

/**
 * Helpers for the MANUAL audit events (M7 D-M7-2) — the ones that aren't a
 * model change and so don't come for free from LogsModelActivity:
 *
 *  - role assignment / removal (the privilege-escalation trail),
 *  - data exports (the convention; no export feature exists in Phase 1 yet),
 *  - auth events (login / logout / failed login / password reset), called from
 *    LogAuthenticationActivity.
 *
 * Every write goes through record(), which keeps the write RLS-safe regardless
 * of the connection's current tenant GUC (see that method).
 */
class Audit
{
    /**
     * Record that a role was granted to a user (who → which role → which client).
     */
    public static function roleGranted(User $user, string $roleName, ?int $teamId): void
    {
        self::record('rbac', fn (ActivityLogger $log) => $log
            ->performedOn($user)
            ->event('role_granted')
            ->withProperties(self::withoutNulls(['role' => $roleName, 'team_id' => $teamId]))
            ->log("granted role {$roleName}"));
    }

    /**
     * Record that a user's role(s) on a client were removed (detach / off-board).
     */
    public static function roleRemoved(User $user, ?int $teamId): void
    {
        self::record('rbac', fn (ActivityLogger $log) => $log
            ->performedOn($user)
            ->event('role_removed')
            ->withProperties(self::withoutNulls(['team_id' => $teamId]))
            ->log('removed roles on client'));
    }

    /**
     * Record an auth event. Called from LogAuthenticationActivity.
     *
     * @param  array<string, mixed>  $properties
     */
    public static function authEvent(string $event, ?User $causer = null, array $properties = []): void
    {
        self::record('auth', function (ActivityLogger $log) use ($event, $causer, $properties) {
            if ($causer !== null) {
                $log->causedBy($causer);
            }

            return $log->event($event)
                ->withProperties($properties)
                ->log(str_replace('_', ' ', $event));
        });
    }

    /**
     * Record a bulk import as ONE attributable summary event (M7 D-M7-2). The
     * per-row "created" auto-logging is suppressed during the import (see
     * ImportLeadsJob) — a large file would otherwise write thousands of
     * causer-less rows that bury the signal. This single event carries who
     * imported, into which subject, and the tallies, mirroring the
     * one-event-per-bulk-op shape of exported(). The causer is passed
     * explicitly because the import runs on the queue, where auth() is empty.
     *
     * @param  array<string, mixed>  $properties
     */
    public static function imported(string $what, array $properties = [], ?Model $subject = null, ?User $causer = null): void
    {
        self::record('import', function (ActivityLogger $log) use ($what, $properties, $subject, $causer) {
            if ($subject !== null) {
                $log->performedOn($subject);
            }

            if ($causer !== null) {
                $log->causedBy($causer);
            }

            return $log->event('imported')
                ->withProperties(['import' => $what] + $properties)
                ->log("imported {$what}");
        });
    }

    /**
     * The export-logging convention (D-M7-2). No export feature exists in Phase
     * 1 (verified — zero export actions in app/), so this has no call site yet.
     * Drop one line into the first export action when it lands, e.g.:
     *
     *   Audit::exported('leads', ['campaign_id' => $id, 'rows' => $count]);
     *
     * @param  array<string, mixed>  $properties
     */
    public static function exported(string $what, array $properties = [], ?Model $subject = null): void
    {
        self::record('export', function (ActivityLogger $log) use ($what, $properties, $subject) {
            if ($subject !== null) {
                $log->performedOn($subject);
            }

            return $log->event('exported')
                ->withProperties(['export' => $what] + $properties)
                ->log("exported {$what}");
        });
    }

    /**
     * Run a manual audit write so its INSERT…RETURNING survives RLS whatever the
     * connection's tenant GUC currently is:
     *  - with a tenant pinned, write directly (the GUC already matches it, so the
     *    row is stamped to and readable for that client);
     *  - with no tenant pinned, the event is ownerless/global — write it on the
     *    global path (runGlobal) so the row is not hidden by a stale tenant GUC
     *    left on the connection (e.g. CreateUser forgets the app context but not
     *    the GUC; a login fires before any context is set). runGlobal, not
     *    cross(): a global audit write is not a cross-tenant *reach*, so it must
     *    not fire the tenant.cross_access tripwire.
     *
     * @param  Closure(ActivityLogger): mixed  $configure
     */
    private static function record(string $logName, Closure $configure): void
    {
        $write = fn () => $configure(activity($logName));

        if (TenantContext::has()) {
            $write();

            return;
        }

        TenantContext::runGlobal($write);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function withoutNulls(array $values): array
    {
        return array_filter($values, fn ($value): bool => $value !== null);
    }
}
