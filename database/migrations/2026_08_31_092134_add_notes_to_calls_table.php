<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CP-5 (N1b) — what the agent typed about this call, in their own words.
 *
 * Text rather than a string: A2 describes agents filling comments at their own
 * pace through the call, so the length is theirs, not a form's. The console caps
 * it at 2000 characters — a note, not a transcript.
 *
 * Nullable with no backfill: most calls will never carry one, and an empty note
 * is a normal state rather than missing data. It lands on the CALL, not the lead,
 * because it describes one conversation; the lead's own fields are CP-3's job.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calls', function (Blueprint $table): void {
            $table->text('notes')->nullable()->after('outcome');
        });
    }

    public function down(): void
    {
        Schema::table('calls', function (Blueprint $table): void {
            $table->dropColumn('notes');
        });
    }
};
