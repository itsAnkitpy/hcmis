<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DIAL-1 F25 — did the dialer place this call? The question DP-12a's 3% rate asks of every
 * row, top and bottom, and the one no existing column could answer.
 *
 * `call_handoffs.was_dialled` already knows, but that note is pruned on the agent's next
 * ring (TH-6), so a report cannot join to it. The timing moments do not tell the two apart
 * either: an agent's own dial carries a note too (CT-16), and a dialled call carries no
 * arrival (A4's D2). And the campaign's dial mode is not the answer — the console never
 * reads it, so an agent can dial by hand on a progressive campaign, and counting those
 * would LOWER the rate, the unsafe side.
 *
 * So the fact is copied onto the row the same way the moments are: the console off the
 * note at wrap-up, the listener straight from the call it is holding.
 *
 * Not nullable, and false for every existing row: no real client list has ever been
 * dialled (G1/G4), so there is nothing to backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->boolean('was_dialled')->default(false)->after('direction');
        });
    }

    public function down(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->dropColumn('was_dialled');
        });
    }
};
