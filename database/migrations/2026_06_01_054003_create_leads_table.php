<?php

use App\Enums\LeadStatus;
use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            // Restrict: deleting a campaign that still holds leads must be a
            // deliberate act (reassign or remove the leads first — FR-LC04).
            $table->foreignId('campaign_id')->constrained()->restrictOnDelete();
            $table->string('name')->nullable();
            $table->string('phone'); // a data field only in Phase 1 — no voice (guardrail #1)
            $table->string('email')->nullable();
            $table->string('region')->nullable(); // FR-LC03 region filter
            $table->string('status')->default(LeadStatus::New->value); // fixed lifecycle funnel (D-M4-3)
            // The config-driven "last outcome" (D-M4-3) points at a stable row.
            $table->foreignId('last_disposition_id')->nullable()->constrained('dispositions')->nullOnDelete();
            $table->unsignedInteger('attempts')->default(0); // FR-LC03 attempt-count filter
            $table->jsonb('custom_fields')->default('{}'); // per-campaign field VALUES (FR-LC02)
            $table->timestamps(); // created_at drives the FR-LC03 "age" filter

            $table->index('tenant_id');
            $table->index('campaign_id');
            $table->index(['tenant_id', 'phone']);  // lookup (dedup itself is M5)
            $table->index(['tenant_id', 'status']); // funnel filters (FR-LC03)
        });

        Rls::enable('leads');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
