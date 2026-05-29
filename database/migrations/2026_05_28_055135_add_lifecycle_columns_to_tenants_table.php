<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M3 §5.1 — lifecycle tracking on tenants. Adds the "when" and "why" without
 * losing the existing `status` column from the M1 migration. Transitions still
 * route through Tenant::transitionTo(), which is the only writer of these.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->timestampTz('suspended_at')->nullable()->after('status');
            $table->timestampTz('archived_at')->nullable()->after('suspended_at');
            $table->text('status_reason')->nullable()->after('archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['suspended_at', 'archived_at', 'status_reason']);
        });
    }
};
