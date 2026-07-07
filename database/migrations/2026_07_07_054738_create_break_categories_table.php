<?php

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
        Schema::create('break_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->string('code');   // e.g. LUNCH_BREAK — stable id; labels are client-editable (BK-1)
            $table->string('label');  // e.g. Lunch Break
            // Null = no limit set yet: the console shows elapsed time only, never
            // an overstay. Real per-type times arrive from ops (open question 2).
            $table->unsignedInteger('time_limit_minutes')->nullable();
            $table->boolean('is_active')->default(true); // deactivate, never delete
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('tenant_id');
        });

        Rls::enable('break_categories');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('break_categories');
    }
};
