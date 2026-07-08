<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The status history (BK-2): one row per STINT — an unbroken stay in one
     * status ("Ready, 09:00–09:42") — closed by the next change, never updated
     * again. Fills the PD-6 seam the who's-free board deliberately left open
     * (agent_presence is one overwritten row; this table is its memory).
     *
     * Break stints also snapshot the category's limit AS IT STOOD (BK-2), so a
     * later admin edit to the category never rewrites yesterday's truth.
     */
    public function up(): void
    {
        Schema::create('agent_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            // History rows are records (the calls-table posture): a deleted user
            // orphans their stints rather than erasing the evidence.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // ready | on_call | wrapping_up | on_break (PresenceStatus). Offline
            // is deliberately NOT a stint — it is the gap between stints.
            $table->string('status');
            // Break stints only: which break type, and its limit at that moment.
            // restrictOnDelete is the DB-level "deactivate, never delete" backstop
            // BK-1 promised — even a superuser path can't orphan history silently.
            $table->foreignId('break_category_id')->nullable()->constrained('break_categories')->restrictOnDelete();
            $table->unsignedInteger('limit_minutes')->nullable();
            $table->timestamp('started_at');
            // Open stint = null. Closed by the next status change ('changed') or
            // lazily after a dead session ('stale', BK-6 — no scheduler).
            $table->timestamp('ended_at')->nullable();
            $table->string('ended_via')->nullable();
            $table->timestamps();

            // My Day / reports: one agent's stints across a day, in order.
            $table->index(['tenant_id', 'user_id', 'started_at']);
            // The live board's read: this client's open stints (ended_at null).
            $table->index(['tenant_id', 'ended_at']);
        });

        Rls::enable('agent_status_history');
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_status_history');
    }
};
