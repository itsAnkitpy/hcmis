<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * inbound-audio.md slice 5 (AU-15, AU-14) — the client's own "thanks for waiting"
 * announcement, played into the waiting area every 60 seconds.
 *
 * The same two-column shape slices 3 and 4 chose, for the same reasons: `settings` is
 * excluded from the audit trail (D-M7-2) and AU-14 needs who ticked the rights box and
 * when, which the activity log carries and D-M7-3 keeps forever.
 *
 * Its OWN rights column rather than sharing another sound's: three uploads, three
 * independent confirmations, and a shared tick could not say which file it was given
 * for.
 *
 * `waiting_message_path` holds the CONVERTED file only, named by its content, so a
 * re-upload is always a new web address — the voice box caches a media file against its
 * address for a year.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('waiting_message_path')->nullable()->after('closed_message_rights_confirmed');
            $table->boolean('waiting_message_rights_confirmed')->default(false)->after('waiting_message_path');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['waiting_message_path', 'waiting_message_rights_confirmed']);
        });
    }
};
