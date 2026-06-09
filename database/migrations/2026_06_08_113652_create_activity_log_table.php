<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The audit log (M7). Spatie's published table, customized for the variant
 * isolation seam (D-M7-1): a nullable tenant_id so one stream holds both
 * tenant-owned events ("client A's lead was edited") and ownerless/global
 * events (logins, super-admin platform actions, the no-auth import job).
 *
 * tenant_id is a bare nullable bigint with NO foreign key (open-question 2):
 * audit history must outlive a tenant's archival, so the row never cascades or
 * blocks on tenant lifecycle.
 *
 * The table is append-only at the DB layer — UPDATE/DELETE are revoked from the
 * app role (D-M7-5). It carries the variant RLS policy, not the standard one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer', 'causer');
            $table->json('attribute_changes')->nullable();
            $table->json('properties')->nullable();

            // The owning client, or NULL for ownerless/global events (D-M7-1).
            // No FK on purpose — audit rows outlive tenant archival.
            $table->unsignedBigInteger('tenant_id')->nullable();

            $table->timestamps();

            // Viewer filters + default "recent first" sort. The composite serves
            // both the "one client's recent activity" query AND tenant_id-only
            // lookups (leftmost prefix), so no standalone tenant_id index is
            // needed; the standalone created_at serves the all-clients sort.
            $table->index(['tenant_id', 'created_at']);
            $table->index('created_at');
        });

        // Variant policy: read = standard tenant wall (ownerless rows hidden
        // from a pinned client, visible on the bypass path); write additionally
        // permits ownerless inserts (D-M7-1).
        Rls::enableNullableTenant('activity_log');

        // Level-1 tamper-evidence: append-only at the DB (D-M7-5).
        Rls::revokeMutations('activity_log');
    }

    public function down(): void
    {
        // Dropping the table also drops its RLS policy; the revoke is moot.
        Schema::dropIfExists('activity_log');
    }
};
