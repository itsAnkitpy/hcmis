<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * inbound-audio.md slice 7 (D6) — which department the caller asked for, and whether
 * the call widened to an agent outside it.
 *
 * A LINK, NOT A NAME. `menu_choice` stays a label on purpose; a label cannot answer
 * "how many Hindi callers this quarter" after a rename or across two menus. Widened
 * cannot be worked out later, because membership changes daily.
 *
 * BOTH TABLES, like `menu_choice`. A missed call's row is written by the flow; an
 * answered call's row is written by the agent's screen, which copies these off the
 * ring-time note (`call_handoffs`) at wrap-up.
 *
 * restrictOnDelete is the "switch off, never delete" backstop (D9, BK-1).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['calls', 'call_handoffs'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('department_id')->nullable()->after('menu_choice')->constrained()->restrictOnDelete();
                $table->boolean('department_widened')->default(false)->after('department_id');
            });
        }
    }

    public function down(): void
    {
        foreach (['calls', 'call_handoffs'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('department_id');
                $table->dropColumn('department_widened');
            });
        }
    }
};
