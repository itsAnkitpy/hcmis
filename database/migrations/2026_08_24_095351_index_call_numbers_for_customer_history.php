<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CH-6 — the two indexes the customer-history panel reads on
 * (`customer-history-panel.md`).
 *
 * The history of one number is `Call::forCustomerNumber()`: outbound calls where
 * `to_number` matches, OR inbound calls where `from_number` does. Without an index
 * on each, that runs on EVERY ring and reads every call the client has ever made —
 * the same shape as the defect fixed in S119 (a per-render DISTINCT over the whole
 * call history on the Call Export's agent dropdown), but on the ring path, where it
 * costs a live call rather than a slow form.
 *
 * Tenant-first so the wall the query already runs behind is the leading column.
 * Additive; no data changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->index(['tenant_id', 'to_number']);   // outbound: the number we dialled
            $table->index(['tenant_id', 'from_number']); // inbound: the number that rang us
        });
    }

    public function down(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'to_number']);
            $table->dropIndex(['tenant_id', 'from_number']);
        });
    }
};
