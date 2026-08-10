<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One lead per phone number per client — the rule the system has always behaved
 * as if it had, now enforced by the database.
 *
 * The original leads migration filed a plain index here with the comment "dedup
 * itself is M5", handing deduplication to the importer alone. Every other write
 * path (Filament create/edit) had nothing stopping a duplicate, and M4's own
 * "move, don't copy" rule means a lead is meant to exist once per client and move
 * between campaigns — so the duplicate was never a shape the system wanted.
 *
 * The unique index serves every lookup the plain one did (same columns, same
 * order), so the old index is dropped rather than left alongside it.
 *
 * ⚠️ No data-cleaning pass runs here, and one must not be added naively. `leads`
 * carries FORCE ROW LEVEL SECURITY (App\Tenancy\Rls::enable), so a migration
 * UPDATE with no tenant marker set on the connection matches ZERO rows and
 * reports success. Any future backfill has to set the bypass marker
 * (App\Tenancy\Rls::BYPASS_GUC = 'on') first, or it silently does nothing.
 * Not needed today: seeded and imported phones are already stored normalized,
 * and CREATE UNIQUE INDEX is not row-filtered — it fails loudly on a real
 * duplicate rather than skipping it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'phone']);
            $table->unique(['tenant_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'phone']);
            $table->index(['tenant_id', 'phone']);
        });
    }
};
