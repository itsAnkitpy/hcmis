<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Call timing — the five moments on a call record (PRD/phase-2/call-timing.md, CT-2).
     *
     * Four of the five moments already have somewhere to live: `calls.started_at` and
     * `calls.answered_at` have existed and been empty since day one, `calls.ended_at`
     * exists and changes meaning (CT-6: the hang-up, not the Done click), and the Done
     * click is the row's own `created_at`. Only the ring is homeless — hence one new
     * column on `calls` and nothing else there.
     *
     * The three on `call_handoffs` are the carrier, not the destination (CT-3): the
     * listener has no logged-in user and must not write the row (D2's single-writer
     * rule), so it stamps its moments onto the handoff note and the agent's screen
     * copies them onto the row at wrap-up. All four moments therefore come off ONE
     * clock — the listener's — and can never disagree with each other.
     *
     * All nullable: a note write is best-effort (TH-2) and a missing moment must
     * degrade to a blank, never to a faked value (CT-6, B3 D4).
     */
    public function up(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            // When the agent's phone started ringing. Copied from the handoff note's own
            // created_at (CT-2) — the note is written immediately before the ring, so the
            // moment needs no column of its own on the carrier.
            $table->timestamp('ringing_at')->nullable()->after('started_at');
        });

        Schema::table('call_handoffs', function (Blueprint $table) {
            // The listener's four moments in transit. The note's own created_at carries
            // the fifth (the ring). Every one of these is stamped ticket-matched (CT-11):
            // a note is filed under an agent and lingers between calls, so "this agent's
            // newest note" is not the same thing as "this call's note".
            $table->timestamp('arrived_at')->nullable();   // the caller reached us
            $table->timestamp('answered_at')->nullable();  // this agent picked up
            $table->timestamp('ended_at')->nullable();     // this agent's part ended
        });
    }

    public function down(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->dropColumn('ringing_at');
        });

        Schema::table('call_handoffs', function (Blueprint $table) {
            $table->dropColumn(['arrived_at', 'answered_at', 'ended_at']);
        });
    }
};
