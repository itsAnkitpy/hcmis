<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CP-3 — city, the fourth field the agent-side customer form captures (A1).
 *
 * `leads.region` already exists but holds a ZONE (North/South/East/West), not a
 * city, so this is a genuinely new field rather than a rename. It gets a column
 * rather than a slot in `leads.custom_fields` because A1 makes city standard for
 * every client, while `custom_fields` only reaches the client's export when a
 * single campaign is filtered (CE-12a).
 *
 * Nullable with no backfill: A3 says the Default CRM form never blocks, so an
 * existing lead with no city is a normal state, not missing data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->string('city')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropColumn('city');
        });
    }
};
