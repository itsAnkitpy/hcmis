<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B2.3b-i QD-7 — the two waiting-room settings a client owns: how long ONE
 * agent's phone rings before we try someone else, and how long a caller may
 * hold before we stop waiting and write them to the missed-call list.
 *
 * Columns, not a `queues` table: a client has exactly one line today (skill-based
 * routing, FR-IN03, does not exist), so a table with one row per client is a
 * table pretending to be a column. When real queues arrive with the menu these
 * two settings move onto the queue — a rename, not a rebuild.
 *
 * Columns, not tenants.settings JSON: these are operational knobs worth having in
 * the audit trail (Tenant::activityLogAttributes), and `settings` is deliberately
 * excluded from it (D-M7-2). The settings DTO is also documented as the tenant's
 * NON-voice settings.
 *
 * Both nullable — null means "use the config fallback" (config/telephony.php
 * queue.ring_seconds / queue.max_hold_seconds), so an existing client needs no
 * backfill and no client is forced to hold an opinion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedSmallInteger('ring_seconds')->nullable()->after('status_reason');
            $table->unsignedSmallInteger('max_hold_seconds')->nullable()->after('ring_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['ring_seconds', 'max_hold_seconds']);
        });
    }
};
