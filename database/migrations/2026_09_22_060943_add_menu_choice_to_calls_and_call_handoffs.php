<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * inbound-audio.md slice 6 (AU-25) — what the caller chose at the menu, carried to the
 * two places that need it.
 *
 * `call_handoffs` is the note the watcher drops at ring-time and the agent's screen
 * reads back (TH-1), so the choice is on the screen while the phone is still ringing —
 * until agent groups exist (slice 7) it is the only thing telling an agent why the
 * person rang.
 *
 * `calls` keeps it for the calls list and the export.
 *
 * THE OPTION'S NAME, NOT ITS KEY. "Sales" survives a client renumbering their menu;
 * "2" does not, and a report grouped by key would silently mix two different things
 * after any edit. "No choice made" is written for a caller who reached an agent by
 * missing twice (AU-23 / AU-24).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->string('menu_choice')->nullable()->after('missed_reason');
        });

        Schema::table('call_handoffs', function (Blueprint $table) {
            $table->string('menu_choice')->nullable()->after('dialled_number');
        });
    }

    public function down(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->dropColumn('menu_choice');
        });

        Schema::table('call_handoffs', function (Blueprint $table) {
            $table->dropColumn('menu_choice');
        });
    }
};
