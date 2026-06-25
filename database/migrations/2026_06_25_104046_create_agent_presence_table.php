<?php

use App\Enums\PresenceStatus;
use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The who's-free board (B2.2 PD-1/PD-6): one OVERWRITTEN row per agent holding
     * "where they are right now". No history — a status change or heartbeat just
     * updates the row (reporting is a future module that reads this, PD-6).
     */
    public function up(): void
    {
        Schema::create('agent_presence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            // One row per agent within a client (PD-6 overwrite). A deleted user
            // takes their board row with them.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // ready | on_call | wrapping_up | on_break | offline (PresenceStatus).
            $table->string('status')->default(PresenceStatus::Offline->value);
            // The heartbeat stamp (PD-4): older than the stale window ⇒ read as
            // offline (not ringable), even if status still says ready.
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            // One board row per agent — the upsert key (setPresence/heartbeat).
            $table->unique(['tenant_id', 'user_id']);
            // The watcher's 2.2b read: this client's free, still-alive agents.
            $table->index(['tenant_id', 'status', 'last_seen_at']);
        });

        Rls::enable('agent_presence');
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_presence');
    }
};
