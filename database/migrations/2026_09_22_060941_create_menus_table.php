<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * inbound-audio.md slice 6 (AU-17 … AU-28) — the spoken menu a caller hears before
 * anyone's phone rings: a greeting, and a key for each thing the client offers.
 *
 * ONE ROW PER MENU, NOT PER CLIENT (AU-18). A client may run a sales menu on one
 * number and a support menu on another, and both are built once and pointed at from
 * `phone_numbers`.
 *
 * THE KEYS LIVE IN ONE FIELD, NOT A SECOND TABLE (slice 6, "Alternate choices").
 * Nothing points at a key except the menu that owns it, and the precedent is already
 * here: `campaigns.custom_fields` holds a list the admin screen edits, cast to an
 * array, audited as one value. Each entry is
 * `{key, label, action, sound_path, sound_rights_confirmed}`.
 *
 * `greeting_path` and every `sound_path` hold the CONVERTED file only, named by its
 * content, so a re-upload is always a new web address — the voice box caches a sound
 * against its address for a year. Its own rights column for the same reason slices 3–5
 * each took one: a shared tick could not say which file it was given for (AU-14).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained(); // restrict: tenants are archived, never hard-deleted
            $table->string('name');
            $table->string('greeting_path')->nullable();
            $table->boolean('greeting_rights_confirmed')->default(false);
            $table->jsonb('options')->default('[]');
            $table->timestamps();

            $table->index('tenant_id'); // the RLS predicate filters on this on every query
        });

        Rls::enable('menus');
    }

    public function down(): void
    {
        Schema::dropIfExists('menus');
    }
};
