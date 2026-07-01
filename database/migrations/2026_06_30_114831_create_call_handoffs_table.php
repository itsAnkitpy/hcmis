<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The listener->browser ticket-handoff drop-off page (B2.4b TH-1). The watcher
     * leaves a call's ticket (a UUID) here at ring-time; the agent's screen reads it
     * by agent identity and stamps it onto the calls row, so an inbound recording can
     * be attached by matching ids (closes the inbound recording-attach gap).
     *
     * Per-call-addressable on purpose (TH-2): keyed (found) by agent_user_id, but the
     * ticket is the handle the screen remembers — and there is deliberately NO unique
     * constraint on agent_user_id alone, so the table can hold >1 note per agent when
     * warm transfer later puts one agent on two calls (the WD-seam forward-compat).
     * Today only one note per agent ever exists (prune-on-write, TH-6).
     */
    public function up(): void
    {
        Schema::create('call_handoffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            // The agent the call is ringing — the handle both sides share at ring-time
            // (TH-2). A deleted user takes their pending notes with them. NOT unique:
            // the drawer must hold >1 note per agent (forward-compat, TH-1).
            $table->foreignId('agent_user_id')->constrained('users')->cascadeOnDelete();
            // The call's per-call id (the UUID the watcher minted in beginCall, TH-2).
            $table->string('ticket');
            $table->timestamps();

            // The screen's ring-time read + the watcher's prune (both by agent within
            // the client). RLS already walls tenant_id; this speeds the agent filter.
            $table->index(['tenant_id', 'agent_user_id']);
        });

        Rls::enable('call_handoffs');
    }

    public function down(): void
    {
        Schema::dropIfExists('call_handoffs');
    }
};
