<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * inbound-audio.md slice 8 (AU-29, AU-34, AU-14) — the message pad: the client's switch
 * and the greeting a caller hears before the beep.
 *
 * The greeting takes the same two-column shape as slices 3–5 (a converted-file path and
 * its own rights tick). The switch is a real column rather than a `settings` key,
 * because `settings` is kept out of the audit trail (D-M7-2) and switching on the
 * recording of callers' voices is exactly what a client may later ask "who did that,
 * and when" about (AU-33's DPDP reasoning).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('voicemail_enabled')->default(false)->after('waiting_message_rights_confirmed');
            $table->string('voicemail_greeting_path')->nullable()->after('voicemail_enabled');
            $table->boolean('voicemail_greeting_rights_confirmed')->default(false)->after('voicemail_greeting_path');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['voicemail_enabled', 'voicemail_greeting_path', 'voicemail_greeting_rights_confirmed']);
        });
    }
};
