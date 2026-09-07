<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The link between a member of staff and their own phone (SEC-1, PP-1).
 *
 * Today only two agent extensions exist, hand-typed into `/etc/asterisk/pjsip.conf`
 * and mapped to user ids in `config/telephony.php`. This column is where a phone
 * starts belonging to the person instead: one extension per user, held on the
 * user's own row (Q4 = P1 — no separate `agent_phones` table, because HCIMS is
 * browser-only and a person never holds two phones).
 *
 * Nullable: most users are not agents and never get one.
 *
 * The unique index is the point of this migration, not a nicety (Q6 rule 3).
 * Allocation reads the highest number in use and writes the next one, so two
 * admins attaching agents at the same moment can read the same number. Without
 * the index they both save and two browsers silently register as the same phone
 * — which is the exact failure SEC-1 exists to remove. With it, the second write
 * fails loudly and the caller retries.
 *
 * `users` carries no row-level security (it is not a tenant table — a phone
 * belongs to the person, and `users` has no client column), so this index is a
 * plain one and no tenant marker is needed to build it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('sip_extension')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['sip_extension']);
            $table->dropColumn('sip_extension');
        });
    }
};
