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
        Schema::create('dispositions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            // Null = a tenant-wide set; set = a per-campaign override set (D-M4-2).
            // Cascade: per-campaign dispositions are sub-records of their campaign.
            $table->foreignId('campaign_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code');   // e.g. SOLD — mirrors config/hcims.php disposition_templates
            $table->string('label');  // e.g. Sold
            $table->boolean('is_contact')->default(false); // "we spoke to a human"
            $table->boolean('is_sale')->default(false);    // "ended in a positive business outcome"
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('campaign_id');
        });

        Rls::enable('dispositions');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dispositions');
    }
};
