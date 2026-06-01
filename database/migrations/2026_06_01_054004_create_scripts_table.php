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
        Schema::create('scripts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            // Null = a tenant-wide script; set = a per-campaign script (D-M4-2).
            $table->foreignId('campaign_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type'); // ScriptType: opening | objection | closing (BRD §6.1)
            $table->text('content');
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('campaign_id');
        });

        Rls::enable('scripts');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scripts');
    }
};
