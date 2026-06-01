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
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained(); // restrict: tenants are archived, never hard-deleted
            $table->string('name');
            $table->string('template'); // CampaignTemplate value — drives M4.E disposition seeding (FR-LC06)
            $table->boolean('is_active')->default(true);
            $table->jsonb('custom_fields')->default('[]'); // per-campaign field DEFINITIONS (FR-LC02); UI lands in M4.D
            $table->timestamps();

            $table->index('tenant_id'); // the RLS predicate filters on this on every query
        });

        Rls::enable('campaigns');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
