<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * inbound-audio.md slice 1 — why a caller is on Missed Calls ("called while closed").
 *
 * Its own column rather than new `outcome` values, so every report and the dialer's 3%
 * figure keep counting exactly as they do today. Nullable: every existing row, and every
 * missed call without a special reason, has none — the screen falls back to the outcome.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->string('missed_reason')->nullable()->after('ended_by');
        });
    }

    public function down(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->dropColumn('missed_reason');
        });
    }
};
