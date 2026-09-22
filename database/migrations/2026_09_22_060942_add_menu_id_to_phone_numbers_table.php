<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * inbound-audio.md slice 6 (AU-18) — which menu answers this number, or none.
 *
 * NULL IS THE DEFAULT AND MEANS TODAY'S BEHAVIOUR: the caller goes straight to the
 * agent board, exactly as every number does before this slice.
 *
 * 🔴 NULL ON DELETE, NOT RESTRICT. Deleting a menu that a number still uses must not
 * break the number — it falls back to the one behaviour that is always defined and
 * always safe. Blocking the delete instead would leave head office unable to remove a
 * menu without first hunting down every number pointing at it, and a menu deleted
 * while a caller is inside it is already handled: the call keeps the greeting it is
 * playing, because the flow read the menu at the door.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_numbers', function (Blueprint $table) {
            $table->foreignId('menu_id')->nullable()->after('campaign_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('phone_numbers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('menu_id');
        });
    }
};
