<?php

namespace App\Http\Controllers;

use App\Enums\CallDirection;
use App\Enums\CallEndedBy;
use App\Enums\CallOutcome;
use App\Enums\MissedReason;
use App\Http\Requests\StoreVoiceMissedCallRequest;
use App\Models\Call;
use App\Telephony\NumberDirectory;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Save one finished AI call as a missed call with the AI's note (AIV-1, AB-4-Q1).
 *
 * The test route sends the call straight from Asterisk to the voice program, so Laravel
 * never saw it and no row exists yet — this creates it. The row has the same shape the
 * inbound flow writes for a caller nobody reached (CallToAgentFlow, the missed-call
 * write), so Missed Calls lists it with no query change.
 *
 * 🔜 AB-6: once calls reach the AI through CallToAgentFlow, the row will already exist,
 * and this must UPDATE it by correlation_id instead — otherwise every AI call is two rows.
 */
class VoiceMissedCallController extends Controller
{
    public function __invoke(StoreVoiceMissedCallRequest $request, NumberDirectory $numbers): JsonResponse
    {
        // Which client, from the number that was dialled — the same lookup inbound calls
        // use, so the sender can never pick a client (AB-4-Q3). Unknown or switched-off
        // number: nothing to file it under, as inbound ND-4.
        $number = $numbers->resolve($request->validated('dialled_number'));

        if ($number === null) {
            throw ValidationException::withMessages([
                'dialled_number' => 'This number belongs to no active client.',
            ]);
        }

        // AB-4-Q8: a retry after a lost reply finds the row the first report saved. No
        // unique index — sibling legs share correlation_id on purpose (CallExportRows).
        // ponytail: two reports at the same instant can still both insert; wrap in a
        // pg_advisory_xact_lock on the call_id if that ever shows up.
        $call = TenantContext::run($number->tenant_id, fn (): Call => Call::query()->firstOrCreate([
            'correlation_id' => $request->validated('call_id'),
        ], [
            'direction' => CallDirection::Inbound,
            'from_number' => $request->validated('caller_number'),
            'to_number' => $number->number,
            // CE-6: the campaign the number belongs to, so the Campaign column a supervisor
            // groups missed calls by is not blank on these rows.
            'campaign_id' => $number->campaign_id,
            'outcome' => CallOutcome::NoAnswer,
            'missed_reason' => MissedReason::ClosedHours,
            'ended_by' => CallEndedBy::from($request->validated('ended_by')),
            // Stored in UTC like every other moment; the voice program may send an offset.
            'started_at' => $request->date('started_at')->utc(),
            'ended_at' => $request->date('ended_at')->utc(),
            'notes' => $request->validated('note'),
        ]));

        return response()->json(['id' => $call->id], $call->wasRecentlyCreated ? 201 : 200);
    }
}
