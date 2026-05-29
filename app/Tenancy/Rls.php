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
