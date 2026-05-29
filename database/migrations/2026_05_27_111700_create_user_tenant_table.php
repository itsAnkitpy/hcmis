<?php

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
        // Membership: which clients a user may access (FR-U03). This is the
        // source of truth for the client switcher. A user's ROLE *within* each
        // client lives in spatie's model_has_roles (team_id = tenant_id).
        // Deliberately NOT tenant-RLS-scoped: it must be queryable before any
        // tenant context exists (e.g. at login, to list allowed clients).
        Schema::create('user_tenant', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'tenant_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_tenant');
    }
};
