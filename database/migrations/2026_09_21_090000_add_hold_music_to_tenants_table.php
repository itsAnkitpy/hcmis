<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * inbound-audio.md slice 3 (AU-13, AU-14) — the client's own hold music.
 *
 * Columns, not `tenants.settings` JSON, for the same reason the queue settings and
 * the report zone beside them are columns: `settings` is deliberately excluded from
 * the audit trail (D-M7-2), and AU-14 requires the rights tick box to be recorded
 * with WHO ticked it and WHEN. Both columns join Tenant::activityLogAttributes().
 *
 * No `hold_music_rights_confirmed_at` and no `..._by`: the activity log already
 * carries both, and D-M7-3 keeps that log forever (config/activitylog.php's
 * `clean_after_days` is unused and nothing is scheduled to prune). Two columns that
 * duplicate a permanent record are two columns that can disagree with it.
 *
 * `hold_music_path` holds the CONVERTED file only, named by its content (slice 3
 * step 3), so a re-upload is always a new name — the voice box caches a media file
 * against its web address for a year, and a stable address would keep the old music
 * playing. No disk column: there is exactly one music disk
 * (config/telephony.php `hold_music.disk`), unlike a recording, which carries its
 * own because it may move.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('hold_music_path')->nullable()->after('timezone');
            $table->boolean('hold_music_rights_confirmed')->default(false)->after('hold_music_path');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['hold_music_path', 'hold_music_rights_confirmed']);
        });
    }
};
