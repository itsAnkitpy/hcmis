<?php

namespace App\Http\Requests;

use App\Enums\CallEndedBy;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One finished AI call, reported by the hcmis-voice program (AIV-1, AB-4-Q3).
 *
 * The sender has no login, so the shared secret IS the guard. It proves who sent the
 * report and nothing more: the client is worked out from the dialled number in the
 * controller, never taken from the body.
 */
class StoreVoiceMissedCallRequest extends FormRequest
{
    /** The phone number shape the Phone Numbers form stores (full international, + first). */
    private const PHONE_NUMBER = 'regex:/^\+[1-9]\d{7,14}$/';

    /**
     * The voice program's secret, compared in constant time. An empty or missing
     * configured secret refuses everything — `hash_equals('', '')` is true, so an
     * .env that forgot the key would otherwise accept any request with no header.
     */
    public function authorize(): bool
    {
        $secret = (string) config('telephony.voice_agent.secret');

        return $secret !== '' && hash_equals($secret, (string) $this->bearerToken());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // The AudioSocket ID the dialplan made for this call. Stored as the ticket, and
            // what makes a retried report land on the same row (AB-4-Q8).
            'call_id' => ['required', 'uuid'],
            'caller_number' => ['nullable', 'string', self::PHONE_NUMBER],
            'dialled_number' => ['required', 'string', self::PHONE_NUMBER],
            // The agent console's own cap on a call note (CP-5): a note, not a transcript.
            'note' => ['required', 'string', 'max:2000'],
            'started_at' => ['required', 'date'],
            'ended_at' => ['required', 'date', 'after_or_equal:started_at'],
            // customer = hung up before the AI saved the message; system = the AI ended it.
            'ended_by' => ['required', Rule::enum(CallEndedBy::class)->only([CallEndedBy::Customer, CallEndedBy::System])],
        ];
    }
}
