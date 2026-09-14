<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DIAL-1 DP-14 — how long the dialer waits before ringing the same lead again.
 *
 * A default, not nullable: unlike `max_attempts`, where null honestly means "no
 * cap", there is no "no gap" case worth keeping — a gap of zero is the bug this
 * column exists to fix. So every campaign, old and new, reads two hours without
 * anyone typing it and without a backfill.
 *
 * Per campaign rather than a constant (S155): every peer dialer makes this
 * configurable, and DialShree — the reference the floor here actually knows —
 * treats it as an admin setting that varies client to client. A collections
 * campaign and a survey campaign want different cadences.
 *
 * 120 is a judgement, not a legal figure. No regulation sets a gap; TCCCPR caps
 * nothing per customer. It sits mid-range against the peers: VICIdial floors its
 * own at 2 minutes and hard-caps below one day, Genesys allows up to 8 hours in
 * one product and 30 days in another. The form's 15-minute floor is where the
 * judgement is defended, not here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->unsignedSmallInteger('retry_gap_minutes')->default(120)->after('max_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropColumn('retry_gap_minutes');
        });
    }
};
