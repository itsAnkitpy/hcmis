<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * National do-not-call register (TRAI DND) — reference data owned by no
     * client and readable by every session, so deliberately NOT a tenant table:
     * no tenant_id, no BelongsToTenant, no Rls::enable() (M6 D-M6-1/-2). Created
     * empty as a schema stub; the TRAI subscription import that fills it and the
     * dial-time scrubbing that reads it are both Phase 3. No client-facing
     * resource writes here — only the future admin import will.
     */
    public function up(): void
    {
        Schema::create('national_dnc_entries', function (Blueprint $table) {
            $table->id();
            $table->string('phone')->unique(); // normalized, same form as dnc_entries.phone
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('national_dnc_entries');
    }
};
