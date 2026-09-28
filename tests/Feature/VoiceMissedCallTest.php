<?php

use App\Enums\CallDirection;
use App\Enums\CallEndedBy;
use App\Enums\CallOutcome;
use App\Enums\MissedReason;
use App\Enums\RoleName;
use App\Filament\Pages\MissedCalls;
use App\Models\Call;
use App\Models\Campaign;
use App\Models\PhoneNumber;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::resetWebRequest();
    TenantContext::forget();
});

/**
 * AIV-1 AB-4 — the hcmis-voice program reports a finished AI call, and it lands on
 * Missed Calls with the AI's note. No login: the shared secret is the guard, and the
 * client comes from the dialled number, never from the sender (AB-4-Q3).
 */
beforeEach(function () {
    config()->set('telephony.voice_agent.secret', 'test-voice-secret');
});

/** A client with one active number, the shape the Phone Numbers screen saves. */
function clientNumber(Tenant $tenant, array $attributes = []): PhoneNumber
{
    return TenantContext::run($tenant->id, fn (): PhoneNumber => PhoneNumber::factory()->create($attributes));
}

/** @return array<string, string> */
function voiceReport(string $dialledNumber, array $overrides = []): array
{
    return array_merge([
        'call_id' => '0b6f1c2e-6f2a-4a8e-9d3b-7c1e2f3a4b5c',
        'caller_number' => '+919876543210',
        'dialled_number' => $dialledNumber,
        'note' => 'AI message — Name: Ravi · About: delivery late · Call back: tomorrow five pm',
        'started_at' => '2026-09-28T20:30:00+05:30',
        'ended_at' => '2026-09-28T20:31:30+05:30',
        'ended_by' => 'system',
    ], $overrides);
}

/** Every call row in every client — RLS hides rows without a context (mistakes-kb 2026-06-13). */
function voiceReportedCalls()
{
    return TenantContext::cross(fn () => Call::query()->get());
}

it('saves the report as a missed call in the client that owns the dialled number', function () {
    $tenant = Tenant::factory()->create();
    $campaign = TenantContext::run($tenant->id, fn (): Campaign => Campaign::factory()->create());
    $number = clientNumber($tenant, ['campaign_id' => $campaign->id]);

    $this->withToken('test-voice-secret')
        ->postJson('/api/voice/missed-calls', voiceReport($number->number))
        ->assertCreated()
        ->assertJsonStructure(['id']);

    $call = voiceReportedCalls()->sole();

    expect($call->tenant_id)->toBe($tenant->id)
        ->and($call->direction)->toBe(CallDirection::Inbound)
        ->and($call->from_number)->toBe('+919876543210')
        ->and($call->to_number)->toBe($number->number)
        ->and($call->campaign_id)->toBe($campaign->id)
        ->and($call->agent_id)->toBeNull()
        ->and($call->outcome)->toBe(CallOutcome::NoAnswer)
        ->and($call->missed_reason)->toBe(MissedReason::ClosedHours)
        ->and($call->ended_by)->toBe(CallEndedBy::System)
        ->and($call->correlation_id)->toBe('0b6f1c2e-6f2a-4a8e-9d3b-7c1e2f3a4b5c')
        ->and($call->notes)->toBe('AI message — Name: Ravi · About: delivery late · Call back: tomorrow five pm')
        // Sent with +05:30, stored in UTC like every other moment.
        ->and($call->started_at->utc()->toIso8601String())->toBe('2026-09-28T15:00:00+00:00')
        ->and($call->ended_at->utc()->toIso8601String())->toBe('2026-09-28T15:01:30+00:00');
});

it('puts the row on Missed Calls with the note showing', function () {
    $tenant = Tenant::factory()->create();
    $number = clientNumber($tenant);
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);

    $this->withToken('test-voice-secret')
        ->postJson('/api/voice/missed-calls', voiceReport($number->number))
        ->assertCreated();

    TenantContext::run($tenant->id, function (): void {
        expect((new MissedCalls)->missedCalls())->toHaveCount(1);
    });

    $this->actingAs($agent)
        ->get('/admin/missed-calls')
        ->assertSuccessful()
        ->assertSee('Call back: tomorrow five pm');
});

it('refuses a report with the wrong secret, or none, and saves nothing', function (?string $token) {
    $number = clientNumber(Tenant::factory()->create());

    $request = $token === null ? $this : $this->withToken($token);

    $request->postJson('/api/voice/missed-calls', voiceReport($number->number))->assertForbidden();

    expect(voiceReportedCalls())->toBeEmpty();
})->with([
    'wrong secret' => 'not-the-secret',
    'no header' => null,
]);

it('refuses every report when no secret is configured, even one with an empty token', function () {
    // hash_equals('', '') is true — without the guard an .env that forgot the key would
    // accept anyone who sends an empty bearer token.
    config()->set('telephony.voice_agent.secret', null);
    $number = clientNumber(Tenant::factory()->create());

    $this->withToken('')
        ->postJson('/api/voice/missed-calls', voiceReport($number->number))
        ->assertForbidden();

    expect(voiceReportedCalls())->toBeEmpty();
});

it('refuses a number no active client owns, and saves nothing', function (bool $switchedOff) {
    $number = clientNumber(Tenant::factory()->create(), ['is_active' => ! $switchedOff]);
    $dialled = $switchedOff ? $number->number : '+15550000000';

    $this->withToken('test-voice-secret')
        ->postJson('/api/voice/missed-calls', voiceReport($dialled))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('dialled_number');

    expect(voiceReportedCalls())->toBeEmpty();
})->with([
    'unknown number' => false,
    'switched-off number' => true,
]);

it('rejects a malformed report', function (array $overrides, string $field) {
    $number = clientNumber(Tenant::factory()->create());

    $this->withToken('test-voice-secret')
        ->postJson('/api/voice/missed-calls', voiceReport($number->number, $overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect(voiceReportedCalls())->toBeEmpty();
})->with([
    'no call id' => [['call_id' => ''], 'call_id'],
    'call id not a uuid' => [['call_id' => 'abc'], 'call_id'],
    'no note' => [['note' => ''], 'note'],
    'note over the console cap' => [['note' => str_repeat('a', 2001)], 'note'],
    'caller number not in + form' => [['caller_number' => '9876543210'], 'caller_number'],
    'ends before it starts' => [['ended_at' => '2026-09-28T20:29:00+05:30'], 'ended_at'],
    'an agent cannot have ended an AI call' => [['ended_by' => 'agent'], 'ended_by'],
]);

it('accepts a withheld caller number', function () {
    $number = clientNumber(Tenant::factory()->create());

    $this->withToken('test-voice-secret')
        ->postJson('/api/voice/missed-calls', voiceReport($number->number, ['caller_number' => null]))
        ->assertCreated();

    expect(voiceReportedCalls()->sole()->from_number)->toBeNull();
});

it('saves a retried report once and answers with the same row', function () {
    $number = clientNumber(Tenant::factory()->create());

    $first = $this->withToken('test-voice-secret')
        ->postJson('/api/voice/missed-calls', voiceReport($number->number))
        ->assertCreated();

    $this->withToken('test-voice-secret')
        ->postJson('/api/voice/missed-calls', voiceReport($number->number))
        ->assertOk()
        ->assertJson(['id' => $first->json('id')]);

    expect(voiceReportedCalls())->toHaveCount(1);
});

it('slows down a flood of reports', function () {
    $number = clientNumber(Tenant::factory()->create());

    foreach (range(1, 60) as $attempt) {
        $this->withToken('test-voice-secret')->postJson('/api/voice/missed-calls', voiceReport($number->number));
    }

    $this->withToken('test-voice-secret')
        ->postJson('/api/voice/missed-calls', voiceReport($number->number))
        ->assertTooManyRequests();
});
