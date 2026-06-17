<?php

use App\Enums\CallbackStatus;
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
        Schema::create('callbacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            // A callback is a child event of its lead — deleting the lead removes it.
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            // Mirror leads' campaign FK: deleting a campaign that still holds rows
            // must be a deliberate act (reassign / remove first).
            $table->foreignId('campaign_id')->constrained()->restrictOnDelete();
            $table->timestamp('scheduled_at'); // when to call back (CALLBACK capture)
            // Set = sticky to that agent (v1); null = pooled (B2, same table). A
            // deleted agent leaves the row as a pooled callback rather than breaking.
            $table->foreignId('owner_agent_id')->nullable()->constrained('users')->nullOnDelete();
            // pending | done — claimed/missed states arrive with pooled (B2).
            $table->string('status')->default(CallbackStatus::Pending->value);
            $table->text('notes')->nullable();
            $table->timestamps();

            // "My due callbacks": owner + status + due-time, tenant-led.
            $table->index(['tenant_id', 'owner_agent_id', 'status', 'scheduled_at']);
            // Serving exclusion (PR2): does this lead have a pending callback?
            $table->index(['tenant_id', 'lead_id', 'status']);
        });

        Rls::enable('callbacks');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('callbacks');
    }
};
