<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `calls` table — the first-class call record / CDR (B3). One row per call,
 * written by the web wrap-up (the single writer, D2); the always-on listener only
 * ENRICHES it later (recording in CP-B3-2; real timing + true outcome trunk-era).
 *
 * Every column the listener owns is NULLABLE so the row is born complete-enough
 * from the web's half and gets enriched without a rewrite — that nullability IS
 * the flexibility lock (D1). See PRD/phase-2/b3-calls-table.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();

            // inbound | outbound — set server-side (CallDirection), never trusted
            // from the browser. The flow already distinguishes the two paths.
            $table->string('direction');
            // Normalized (PhoneNumber). Nullable for what v1 honestly can't know:
            // the inbound DID isn't configured in the lab (to_number null inbound),
            // and an anonymous caller has no number (from_number null). The trunk-era
            // watcher can enrich precise numbers from the SIP signalling.
            $table->string('from_number')->nullable();
            $table->string('to_number')->nullable();

            // All four business FKs are nullable: an ad-hoc typed-number call has
            // no lead/campaign/disposition (D5). A CDR is a historical fact, so a
            // deleted parent NULLs the link rather than erasing the call record
            // (nullOnDelete, deliberately unlike callbacks which cascade).
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('users')->nullOnDelete(); // who handled it (auth at wrap-up)
            $table->foreignId('disposition_id')->nullable()->constrained()->nullOnDelete();

            // answered | no_answer | abandoned (NOT blocked — D5). NULLABLE: v1 is
            // sourced from the disposition's is_contact and left null for the
            // dispositionless ad-hoc/unmatched path; the trunk-era watcher fills /
            // overrides it with the real line-result via correlation_id (S39 banner).
            $table->string('outcome')->nullable();

            // The UUID "tracking number" the wrap-up stamps so the listener can
            // attach the recording (and, trunk-era, the real timing/outcome) to the
            // right row. Populated in CP-B3-2; null in CP-B3-1.
            $table->uuid('correlation_id')->nullable();

            // Precise timing — listener enrichment, trunk-era (D4). Left null in v1.
            $table->timestamp('started_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            // COARSE in v1: = wrap-up time (a good-enough "roughly when"), not faked
            // precision. Precise teardown timing is trunk-era enrichment.
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();

            // The merged stereo recording — attached async BY correlation_id in
            // CP-B3-2. Null until then.
            $table->string('recording_disk')->nullable();
            $table->string('recording_path')->nullable();

            $table->timestamps(); // created_at ≈ when the call was logged

            $table->index(['tenant_id', 'created_at']); // reporting (daily summaries)
            $table->index('correlation_id');            // recording attach (CP-B3-2)
            $table->index('lead_id');                   // a lead's call history
        });

        Rls::enable('calls');
    }

    public function down(): void
    {
        Schema::dropIfExists('calls');
    }
};
