<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * hold.md H-7 — where the held total comes to rest.
 *
 * One call can be held three times, so there is no pair of moments to store and a
 * total is the only honest shape (H-1). That is the one deliberate exception to CT-1
 * ("store moments, never durations"), named in advance when CT-1 was written.
 *
 * The note carries it from the always-on program to the screen; the calls column is
 * what the screen copies it onto at the Done click, exactly as it already does for the
 * four timing moments and for who hung up.
 *
 * Nullable, with no default, and deliberately so: a call recorded before this ships has
 * no hold information at all and must read as a blank rather than as a confident zero.
 * A call recorded after it and never held reads 0, which is true. Zero is a measurement,
 * blank is the absence of one — the distinction S109 found wrong in the Wrap-up column.
 *
 * No backfill. Old rows stay blank for ever, which is honest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_handoffs', function (Blueprint $table) {
            $table->integer('hold_seconds')->nullable()->after('ended_by');
        });

        Schema::table('calls', function (Blueprint $table) {
            $table->integer('hold_seconds')->nullable()->after('ended_by');
        });
    }

    public function down(): void
    {
        Schema::table('call_handoffs', function (Blueprint $table) {
            $table->dropColumn('hold_seconds');
        });

        Schema::table('calls', function (Blueprint $table) {
            $table->dropColumn('hold_seconds');
        });
    }
};
