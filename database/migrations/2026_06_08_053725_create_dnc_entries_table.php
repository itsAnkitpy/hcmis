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
        Schema::create('dnc_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->string('phone');                     // normalized customer number to never dial (PhoneNumber::normalize)
            $table->string('source');                    // DncSource: customer_request | manual | legal
            $table->text('reason')->nullable();          // why it's listed (ticket ref, legal basis)
            $table->timestamp('expires_at')->nullable(); // stored + shown; dial-time enforcement is Phase 3
            $table->timestamps();

            // One number per client, once (D-M6-4). tenant_id leads the index, so
            // the same number may be listed by different clients (separate rows),
            // and this index also serves tenant-scoped phone lookups.
            $table->unique(['tenant_id', 'phone']);
        });

        Rls::enable('dnc_entries');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dnc_entries');
    }
};
