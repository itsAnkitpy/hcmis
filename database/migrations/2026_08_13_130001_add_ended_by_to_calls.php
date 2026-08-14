<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * call-export.md CE-11 — where the who-hung-up value comes to rest.
 *
 * The handoff note carries it from the listener to the screen; this is the column the
 * screen copies it onto at wrap-up, exactly as it already does for the four timing
 * moments. A separate migration from the handoff one because it is a separate table
 * with a separate lifetime: the note is swept away by the agent's next call, the call
 * row is the permanent record.
 *
 * Nullable, like every other column the listener owns (B3 D1): a call ended by an
 * error, and every row written before this shipped, honestly has no value.
 *
 * A string rather than a boolean "agent hung up": there are three knowable answers
 * (the customer, the agent, or us giving up on their behalf when the hold ran out),
 * and a boolean would have to lie about the third.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->string('ended_by')->nullable()->after('ended_at');
        });
    }

    public function down(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->dropColumn('ended_by');
        });
    }
};
