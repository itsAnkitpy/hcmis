<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * call-export.md CE-10 — the one time zone a client's reports are read in.
 *
 * The app stores and shows UTC; the floor lives five and a half hours ahead. A
 * spreadsheet leaving our system with bare UTC timestamps WILL be read as local
 * time by somebody. Amazon Connect and Genesys Cloud both put the zone on the
 * report with a default handed down from the account; neither ships bare UTC
 * with a label. This column is that default.
 *
 * Deliberately built the same way as the queue settings beside it
 * (2026_08_07_072935_add_queue_settings_to_tenants_table.php):
 *
 *   - a column, not `tenants.settings` JSON, because this is an operational knob
 *     worth having in the audit trail and `settings` is excluded from it
 *     (D-M7-2). Changing it moves calls between days in every report from that
 *     moment on, which is exactly the change worth a record of.
 *   - NULLABLE with no database default, so the fallback lives in one place in
 *     PHP (Tenant::reportTimezone() -> config('app.report_timezone')). A
 *     database default would force an opinion onto every existing client and
 *     make "a client with no zone set" untestable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('timezone')->nullable()->after('max_hold_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
