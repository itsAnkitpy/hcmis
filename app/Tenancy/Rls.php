<?php

namespace App\Tenancy;

use Illuminate\Support\Facades\DB;

/**
 * Postgres Row-Level Security — D-002 layer 3, the DB-enforced backstop that
 * catches any tenant scope the app forgot. Call enable() from each
 * tenant-owned table's migration (M4+). The matching session GUCs are set by
 * TenantContext via SET LOCAL inside a transaction (pooler-safe).
 *
 * NOTE: RLS only binds when the app connects as a NON-superuser role that does
 * not have BYPASSRLS. Superusers and BYPASSRLS roles skip RLS entirely, even
 * with FORCE. The app role (hcmis_app) is deliberately non-superuser.
 */
class Rls
{
    /** Session GUC holding the active tenant id (empty string = none). */
    public const TENANT_GUC = 'app.current_tenant_id';

    /** Session GUC that, when 'on', allows the audited cross-tenant path. */
    public const BYPASS_GUC = 'app.bypass_rls';

    /**
     * Enable tenant-isolating RLS on a table: ENABLE + FORCE (so even the table
     * owner obeys) + a default-deny policy. No tenant set on the connection
     * means zero rows, never every row.
     */
    public static function enable(string $table, string $tenantColumn = 'tenant_id'): void
    {
        self::assertIdentifier($table);
        self::assertIdentifier($tenantColumn);

        $policy = "{$table}_tenant_isolation";

        $predicate = sprintf(
            "(current_setting('%s', true) = 'on' OR %s = NULLIF(current_setting('%s', true), '')::bigint)",
            self::BYPASS_GUC,
            $tenantColumn,
            self::TENANT_GUC,
        );

        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement("DROP POLICY IF EXISTS {$policy} ON {$table}");
        DB::statement("CREATE POLICY {$policy} ON {$table} FOR ALL USING {$predicate} WITH CHECK {$predicate}");
    }

    /**
     * Variant of enable() for a table that holds BOTH tenant-owned rows and
     * ownerless/global rows under one isolation wall — the M7 audit log
     * (D-M7-1). Logins, super-admin platform actions and the no-auth import job
     * write rows with no tenant context (tenant_id IS NULL).
     *
     * READ predicate — three branches:
     *   1. the bypass path (HC staff) sees everything;
     *   2. a pinned client sees its own rows (tenant_id = the pinned tenant);
     *   3. ownerless rows (tenant_id IS NULL) are visible ONLY when no client is
     *      pinned (the GUC is empty).
     * Branch 3 is what keeps a pinned client from ever seeing a global event,
     * while still letting an ownerless INSERT read its own row back: Eloquent's
     * `INSERT ... RETURNING id` re-reads the new row through this USING policy,
     * so without branch 3 an ownerless save() (e.g. a login) would be rejected.
     *
     * WRITE check is widened over enable(): the standard check rejects an
     * ownerless insert (no GUC → `NULL = NULL` → not true), so we additionally
     * permit `tenant_id IS NULL`. A pinned client still cannot insert a row
     * stamped to another tenant — that path stays default-denied.
     */
    public static function enableNullableTenant(string $table, string $tenantColumn = 'tenant_id'): void
    {
        self::assertIdentifier($table);
        self::assertIdentifier($tenantColumn);

        $policy = "{$table}_tenant_isolation";

        $using = sprintf(
            "(current_setting('%s', true) = 'on'"
            ." OR %s = NULLIF(current_setting('%s', true), '')::bigint"
            ." OR (%s IS NULL AND NULLIF(current_setting('%s', true), '') IS NULL))",
            self::BYPASS_GUC,
            $tenantColumn,
            self::TENANT_GUC,
            $tenantColumn,
            self::TENANT_GUC,
        );

        $withCheck = sprintf(
            "(current_setting('%s', true) = 'on' OR %s IS NULL OR %s = NULLIF(current_setting('%s', true), '')::bigint)",
            self::BYPASS_GUC,
            $tenantColumn,
            $tenantColumn,
            self::TENANT_GUC,
        );

        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement("DROP POLICY IF EXISTS {$policy} ON {$table}");
        DB::statement("CREATE POLICY {$policy} ON {$table} FOR ALL USING {$using} WITH CHECK {$withCheck}");
    }

    /**
     * Make a table append-only at the database layer by revoking UPDATE and
     * DELETE from the application role — the Level-1 tamper-evidence backstop
     * for the audit log (D-M7-5). INSERT and SELECT remain; nothing the app can
     * run will alter or remove a logged row. Pairs with the read-only Filament
     * viewer so immutability holds at both the UI and the DB.
     *
     * The role defaults to the connection's configured username (the app role).
     * Postgres lets an owner revoke its own ordinary privileges, so this binds
     * even where the app role also owns the table (the single-role local/test
     * setup); a Phase-3 PII-scrub job must therefore run under a separate
     * privileged role (carry-in — see m7-audit-logging.md §3 D-M7-3/-5).
     */
    public static function revokeMutations(string $table, ?string $role = null): void
    {
        self::assertIdentifier($table);

        $role ??= (string) DB::connection()->getConfig('username');
        self::assertIdentifier($role);

        DB::statement("REVOKE UPDATE, DELETE ON {$table} FROM {$role}");
    }

    /**
     * Remove tenant RLS from a table (migration rollback / teardown).
     */
    public static function disable(string $table): void
    {
        self::assertIdentifier($table);

        $policy = "{$table}_tenant_isolation";

        DB::statement("DROP POLICY IF EXISTS {$policy} ON {$table}");
        DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
    }

    /**
     * Guard against identifier injection — these come from migration code, not
     * user input, but RLS is security-critical so we stay strict.
     */
    private static function assertIdentifier(string $identifier): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/i', $identifier) !== 1) {
            throw new \InvalidArgumentException("Unsafe SQL identifier [{$identifier}].");
        }
    }
}
