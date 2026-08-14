<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * call-export.md CE-6 + CE-11 — two facts the always-on listener already knows and
 * currently throws away, carried to the screen that writes the call row.
 *
 * One migration for both because they are the same shape on the same table, written
 * by the same code paths, and read at the same moment (wrap-up).
 *
 *   dialled_number  WHICH of our numbers the caller rang (CE-6). Today this dies with
 *                   the call, which is why most inbound rows export with a blank
 *                   Campaign — the single biggest hole in the export's menu. Stored as
 *                   the number, not the campaign id: the number is the fact the
 *                   listener holds, the campaign is a lookup that can change (the same
 *                   reasoning as LW-2 — carry the moment, not the derived value).
 *
 *   ended_by        WHICH SIDE hung up (CE-11). The teardown already branches on
 *                   exactly this and writes nothing down.
 *
 * Both nullable: an outbound call was never dialled in to us, and a call torn down by
 * an error has no side that hung up. CE-4's honesty rule — a blank beats a guess.
 *
 * The note is pruned on each new ring (TH-6) and never read after wrap-up, so there is
 * nothing to backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_handoffs', function (Blueprint $table) {
            $table->string('dialled_number')->nullable()->after('ticket');
            $table->string('ended_by')->nullable()->after('ended_at');
        });
    }

    public function down(): void
    {
        Schema::table('call_handoffs', function (Blueprint $table) {
            $table->dropColumn(['dialled_number', 'ended_by']);
        });
    }
};
