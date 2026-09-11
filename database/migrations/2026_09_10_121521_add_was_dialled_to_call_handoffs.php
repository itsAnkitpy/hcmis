<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DIAL-1 F15 — WHO PLACED THIS CALL, carried to the screen that writes the call row.
 *
 * A call the progressive dialer placed reaches the agent exactly as an inbound one does:
 * their phone rings and their console pops. That is the whole point of DF-2's reframe and
 * why the dialer cost so little — but it means the console has no way to tell the two
 * apart, so every answered dial was filed in `calls` as a call the customer made to US.
 * Direction is the column every reader picks the customer's side off (Call::forCustomer,
 * the export, the calls list), and it is the one an outbound-volume or abandoned-rate
 * report counts on.
 *
 * A boolean, not a lead id: the question the screen has to answer is "did we place this",
 * and it already finds the lead by matching the ringing number (lookupLead). One column,
 * one fact, no second way to say the same thing — the mistake F16 was.
 *
 * Not nullable, because "we do not know who placed a call" is not a real state: the
 * listener writes this note and always knows. False is the honest default for every note
 * that already exists — all of them are inbound, the dialer having never run in anger.
 *
 * The note is pruned on each new ring (TH-6) and never read after wrap-up, so there is
 * nothing to backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_handoffs', function (Blueprint $table) {
            $table->boolean('was_dialled')->default(false)->after('dialled_number');
        });
    }

    public function down(): void
    {
        Schema::table('call_handoffs', function (Blueprint $table) {
            $table->dropColumn('was_dialled');
        });
    }
};
