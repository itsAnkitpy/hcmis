<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * inbound-audio.md slice 7 (D3) — how long a caller waits for their department before
 * any free agent may take them. Beside ring_seconds and max_hold_seconds, and the same
 * shape: nullable, null means the config fallback (telephony.queue.department_wait_seconds).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedSmallInteger('department_wait_seconds')->nullable()->after('max_hold_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('department_wait_seconds');
        });
    }
};
