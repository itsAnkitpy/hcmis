<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DIAL-1 DP-3 — who is currently working this lead.
 *
 * Two agents on one campaign are already handed the SAME lead today: `attempts`
 * is bumped at wrap-up, not at dial, and the console's skip list is per-session.
 * So the customer gets two calls a minute apart. This column is the claim that
 * stops it, and it stops it for the coming dialer at the same time.
 *
 * One nullable timestamp and nothing else. No `claimed_by`: it only answers "who
 * is holding this", which is a debugging nicety rather than correctness (DQ-4).
 * A claim older than Lead::CLAIM_TTL_SECONDS is treated as expired BY THE SERVING
 * QUERY, so a crashed dialer or a closed browser tab needs no release job and no
 * timeout worker — the expiry is a WHERE clause.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->timestamp('claimed_at')->nullable()->after('attempts');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropColumn('claimed_at');
        });
    }
};
