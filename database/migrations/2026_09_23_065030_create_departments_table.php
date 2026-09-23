<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * inbound-audio.md slice 7 — a client's departments, and which of its agents sit in
 * each. A menu key can ring a department; the caller waits for its people first, then
 * widens to anyone (D3).
 *
 * A PLAIN LIST, NO LEVELS (D1). An agent may be in several departments. Skill levels
 * and ranks arrive later as a number on the membership row, not a redesign.
 *
 * SWITCH OFF, NEVER DELETE (D9, the BK-1 break-types rule). `is_active` takes a
 * department out of use; calls point at it with restrictOnDelete, so the database
 * refuses to delete one any call remembers.
 *
 * The membership table carries tenant_id so RLS walls it like every other
 * tenant-owned table (D-002 layer 3), not only through its parent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained(); // restrict: tenants are archived, never hard-deleted
            $table->string('name');
            $table->boolean('is_active')->default(true); // switch off, never delete
            $table->timestamps();

            $table->index('tenant_id');
        });

        Rls::enable('departments');

        Schema::create('department_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['department_id', 'user_id']);
            $table->index('tenant_id');
            $table->index('user_id');
        });

        Rls::enable('department_user');
    }

    public function down(): void
    {
        Schema::dropIfExists('department_user');
        Schema::dropIfExists('departments');
    }
};
