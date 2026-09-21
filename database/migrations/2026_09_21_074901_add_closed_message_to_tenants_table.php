<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * inbound-audio.md slice 4 (AU-2's third closed-hours choice, AU-14) — the client's
 * own "we're closed" announcement.
 *
 * The same two-column shape slice 3 chose for hold music, for the same reasons:
 * `settings` is excluded from the audit trail (D-M7-2) and AU-14 needs who ticked the
 * rights box and when, which the activity log carries and D-M7-3 keeps forever. No
 * `..._at` / `..._by` columns duplicating that log.
 *
 * Its OWN rights column rather than sharing the music one: they are two uploads and
 * two independent confirmations, and a shared tick could not say which file it was
 * given for.
 *
 * `closed_message_path` holds the CONVERTED file only, named by its content, so a
 * re-upload is always a new web address — the voice box caches a media file against
 * its address for a year.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('closed_message_path')->nullable()->after('hold_music_rights_confirmed');
            $table->boolean('closed_message_rights_confirmed')->default(false)->after('closed_message_path');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['closed_message_path', 'closed_message_rights_confirmed']);
        });
    }
};
