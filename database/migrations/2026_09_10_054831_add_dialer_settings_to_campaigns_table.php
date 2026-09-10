<?php

use App\Enums\CampaignCategory;
use App\Enums\DialMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DIAL-1 DP-4 — the settings a campaign needs before anything can dial for it.
 *
 * Seven columns, every one with a default, so this migration changes the
 * behaviour of exactly nothing: every existing campaign reads `manual` and is
 * not dialing, which is what it was doing yesterday. The dialer in slice 3 is
 * the first thing to read any of them.
 *
 * Two of the seven exist only because TCCCPR 2018 forces them (DQ-6 G2 and G3):
 * `category`, which decides whether the 10:00–21:00 time band applies, and
 * `caller_id`, because promotional and service calls must come from different
 * number series (140 vs 1601) and config/telephony.php holds one number for the
 * whole system.
 *
 * Defaults worth their reasoning:
 *  - `category` is Promotional, the stricter one (F3). See CampaignCategory.
 *  - the window defaults to the legal band rather than null (F2). A null window
 *    plus promotional plus progressive is precisely the illegal case G2 exists
 *    to stop, and a default costs no validator code.
 *  - `max_attempts` is nullable meaning no cap, matching what every campaign
 *    does today. DialShree spells the same idea `0`; nullable is the Laravel one.
 *
 * `time` columns, not strings: slice 3 compares them in SQL, and a real type
 * is the native answer. NOT cast on the model — S118 found Filament converts a
 * time-only value against the panel's reading zone and turned 09:30 into 04:00.
 * A calling window is wall-clock text with no date to convert from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->string('dial_mode')->default(DialMode::Manual->value)->after('template');
            $table->boolean('is_dialing')->default(false)->after('dial_mode');
            $table->string('category')->default(CampaignCategory::Promotional->value)->after('is_dialing');
            $table->string('caller_id')->nullable()->after('category'); // null falls back to telephony.outbound.caller_id
            $table->time('dial_start_time')->default('10:00')->after('caller_id');
            $table->time('dial_end_time')->default('21:00')->after('dial_start_time');
            $table->unsignedSmallInteger('max_attempts')->nullable()->after('dial_end_time'); // null = no cap
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropColumn([
                'dial_mode',
                'is_dialing',
                'category',
                'caller_id',
                'dial_start_time',
                'dial_end_time',
                'max_attempts',
            ]);
        });
    }
};
