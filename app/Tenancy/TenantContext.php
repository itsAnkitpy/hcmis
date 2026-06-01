<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Holds the active tenant for the current execution path and enforces
 * default-deny: code that touches a tenant-owned model with no tenant set
 * (and outside an audited cross-tenant block) gets blocked, never silently
 * handed every tenant's rows.
 *
 * This is D-002 control #1 — explicit context propagation. The context is
 * NEVER derived from auth(); every entry path (web, queue jobs, telephony
 * events, scheduled commands) must set it explicitly via run().
 *
 * Two layers move together:
 *  - app layer: the static tenant id read by TenantScope (Checkpoint A);
 *  - DB layer: Postgres session GUCs set via SET LOCAL so RLS enforces the
 *    same tenant at the database (Checkpoint B). SET LOCAL is used inside a
 *    transaction so it auto-clears at transaction end — safe with or without
 *    a connection pooler.
 */
class TenantContext
{
    private static ?int $tenantId = null;

    private static bool $crossTenant = false;

    /**
     * Run a callback bound to a single tenant. Nesting-safe — the previous
     * context (app + DB) is restored afterwards.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function run(Tenant|int $tenant, Closure $callback): mixed
    {
        $tenantId = $tenant instanceof Tenant ? (int) $tenant->getKey() : $tenant;

        return self::bind($tenantId, false, $callback);
    }

    /**
     * The audited cross-tenant escape hatch (D-002 layer 2) — for the few
     * legitimate cross-tenant needs: leadership dashboards (§6.10),
     * multi-tenant TLs (FR-U03), erasure-by-customer-id (FR-QC07).
     *
     * Every use is logged now; M7 replaces this with spatie/activitylog
     * (causer, IP, user-agent).
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function cross(Closure $callback, ?string $reason = null): mixed
    {
        Log::info('tenant.cross_access', [
            'reason' => $reason,
            'tenant_in_scope' => self::$tenantId,
        ]);

        return self::bind(self::$tenantId, true, $callback);
    }

    /**
     * The active tenant id, or null when none is set.
     */
    public static function id(): ?int
    {
        return self::$tenantId;
    }

    /**
     * Whether a tenant is currently set.
     */
    public static function has(): bool
    {
        return self::$tenantId !== null;
    }

    /**
     * Whether we are inside an audited cross-tenant block.
     */
    public static function isCrossTenant(): bool
    {
        return self::$crossTenant;
    }

    /**
     * Resolve the active tenant model, or null when none is set.
     */
    public static function current(): ?Tenant
    {
        return self::$tenantId === null ? null : Tenant::find(self::$tenantId);
    }

    /**
     * Set the app-layer context for a request/lifecycle boundary WITHOUT opening
     * a transaction or touching the DB session. Use this from the web request
     * bridge, where the framework owns the lifecycle and layer-2 scoping is the
     * guarantee. Discrete units of work (jobs, commands) should use run()/cross()
     * instead, which also bind RLS via SET LOCAL.
     */
    public static function set(Tenant|int|null $tenant): void
    {
        self::$tenantId = $tenant instanceof Tenant ? (int) $tenant->getKey() : $tenant;
        self::$crossTenant = false;
    }

    /**
     * Clear the app-layer context. Used at the end of a request and in test
     * teardown — the DB-layer GUC is transaction-scoped and clears itself when
     * the transaction ends.
     */
    public static function forget(): void
    {
        self::$tenantId = null;
        self::$crossTenant = false;
    }

    /**
     * Stamp BOTH the app-layer context and the Postgres session marker for a
     * WEB request, using plain SET (session-scoped) rather than SET LOCAL.
     *
     * Why not the run()/cross() path: those wrap their work in a transaction and
     * use SET LOCAL, which lives only inside that transaction. A web request has
     * NO request-long transaction (we deliberately avoid one so mail/HTTP never
     * run inside a DB transaction). A SET LOCAL stamped at request start would
     * evaporate before Filament's first query, leaving the tenant GUC empty —
     * and the RLS default-deny policy would then return ZERO rows on every
     * tenant-owned table. Plain SET persists for the whole request, across
     * Filament's many nested write transactions, and dies with the connection.
     *
     * Set-fresh-at-start (D-M4-1): call this at the very start of every request.
     * Overwriting the marker up-front means a crashed prior request on a reused
     * connection (Octane) can never leak its tenant into this one — Octane-safe
     * by construction, not merely by the exit reset.
     *
     * @param  bool  $crossTenant  true for cross-client HC staff (no single
     *                             current client): app scope is bypassed and the
     *                             RLS bypass GUC is turned on — the audited
     *                             cross-tenant posture, mirrored from cross().
     */
    public static function applyWebRequest(?int $tenantId, bool $crossTenant): void
    {
        self::$tenantId = $tenantId;
        self::$crossTenant = $crossTenant;

        self::applyWebSession($tenantId, $crossTenant);
    }

    /**
     * Reset the web marker to the safe default (no tenant, no bypass → DB
     * default-deny). Called on request exit as belt-and-suspenders; the primary
     * guarantee is applyWebRequest() set-fresh-at-start on the next request.
     * Best-effort: a dying connection at terminate() must not raise.
     */
    public static function resetWebRequest(): void
    {
        self::$tenantId = null;
        self::$crossTenant = false;

        try {
            self::applyWebSession(null, false);
        } catch (Throwable) {
            // Connection already gone at end of request — the next request's
            // set-fresh-at-start covers it, and the connection is reset anyway.
        }
    }

    /**
     * Set app + DB context, run the callback, then restore the previous
     * context. Ensures a transaction so SET LOCAL is valid and pooler-safe.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    private static function bind(?int $tenantId, bool $crossTenant, Closure $callback): mixed
    {
        $previousTenantId = self::$tenantId;
        $previousCrossTenant = self::$crossTenant;

        self::$tenantId = $tenantId;
        self::$crossTenant = $crossTenant;

        $connection = DB::connection();
        $openedTransaction = false;

        if ($connection->transactionLevel() === 0) {
            $connection->beginTransaction();
            $openedTransaction = true;
        }

        self::applySession($tenantId, $crossTenant);

        try {
            $result = $callback();

            if ($openedTransaction) {
                $connection->commit();
            }

            return $result;
        } catch (Throwable $e) {
            if ($openedTransaction) {
                $connection->rollBack();
            }

            throw $e;
        } finally {
            self::$tenantId = $previousTenantId;
            self::$crossTenant = $previousCrossTenant;

            // When we opened the transaction it has already ended, taking the
            // SET LOCAL with it. When we ran inside an existing transaction,
            // restore the GUCs for the surrounding context — best-effort, since
            // the transaction may be in an aborted state after a DB error.
            if (! $openedTransaction) {
                try {
                    self::applySession($previousTenantId, $previousCrossTenant);
                } catch (Throwable) {
                    // Aborted transaction will be rolled back by its owner.
                }
            }
        }
    }

    /**
     * Push the active tenant onto the Postgres session via SET LOCAL so RLS
     * filters by the same tenant the app layer is scoped to.
     */
    private static function applySession(?int $tenantId, bool $crossTenant): void
    {
        $connection = DB::connection();

        $tenantValue = $tenantId === null ? "''" : "'".$tenantId."'";
        $connection->statement('SET LOCAL '.Rls::TENANT_GUC.' = '.$tenantValue);

        $connection->statement('SET LOCAL '.Rls::BYPASS_GUC.' = '.($crossTenant ? "'on'" : "'off'"));
    }

    /**
     * Push the active tenant onto the Postgres session via plain SET (NOT SET
     * LOCAL) so the marker persists for the whole web request across Filament's
     * nested transactions. See applyWebRequest() for why SET LOCAL is wrong here.
     */
    private static function applyWebSession(?int $tenantId, bool $crossTenant): void
    {
        $connection = DB::connection();

        $tenantValue = $tenantId === null ? "''" : "'".$tenantId."'";
        $connection->statement('SET '.Rls::TENANT_GUC.' = '.$tenantValue);

        $connection->statement('SET '.Rls::BYPASS_GUC.' = '.($crossTenant ? "'on'" : "'off'"));
    }
}
