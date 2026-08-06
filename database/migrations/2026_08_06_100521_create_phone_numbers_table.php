<?php

use App\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The number -> client list (B2.3a ND-2). An inbound call arrives on a number;
     * this table is the only thing that says which client owns it, replacing the
     * hardcoded Set(tenantid=1) in the dialplan. FR-IN01 ("an incoming number maps
     * to one tenant + one queue") is what this satisfies; the queue half waits for
     * B2.3b, so no queue column is created yet.
     *
     * Four fields on purpose. No carrier, no country, no billing — none has a use.
     */
    public function up(): void
    {
        Schema::create('phone_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained(); // restrict: tenants are archived, never hard-deleted
            // E.164 as the carrier hands it to us (+19787966797 today, +91... in
            // production — D-004 forbids assuming length or country). Unique across
            // the WHOLE system, not per tenant: two clients claiming one number would
            // route calls to the wrong company, which is a cross-client data exposure,
            // not a routing bug. The unique index binds under RLS (it is enforced in
            // storage, not by the policy), so a client inserting another client's
            // number gets a duplicate-key error for a row it cannot see — a deliberate,
            // tiny existence leak, traded for a guarantee nobody has to remember.
            $table->string('number')->unique();
            // Optional: which campaign the calls on this number belong to. Nullable
            // because a client may own a number before assigning it. Guarded in the
            // app (ND-5) so the campaign must belong to the same client.
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // The screen's list (this client's numbers, live ones first). The resolver's
            // own read goes through the unique index on `number`.
            $table->index(['tenant_id', 'is_active']);
        });

        Rls::enable('phone_numbers');
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_numbers');
    }
};
