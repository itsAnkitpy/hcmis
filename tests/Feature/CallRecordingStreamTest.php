<?php

use App\Enums\RoleName;
use App\Models\ActivityLog;
use App\Models\Call;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::resetWebRequest();
    TenantContext::forget();
});

/** A call with the recording attached (CP-B3-2 shape), owned by the given client. */
function recordedCall(Tenant $tenant): Call
{
    return TenantContext::run($tenant->id, fn (): Call => Call::factory()->withRecording()->create());
}

/** Put a stand-in audio file where the call's recording_path points. */
function putRecordingFor(Call $call): void
{
    Storage::disk($call->recording_disk)->put($call->recording_path, 'FAKE-MP3-BYTES');
}

/** A larger stand-in so byte ranges (HD-2) have room to slice; default 10 000 bytes. */
function putLargeRecordingFor(Call $call, int $bytes = 10000): void
{
    Storage::disk($call->recording_disk)->put($call->recording_path, str_repeat('A', $bytes));
}

/** Count the recording.accessed audit entries for a call (RLS-scoped to its client). */
function recordingAccessCount(Call $call, Tenant $tenant): int
{
    return TenantContext::run($tenant->id, fn (): int => ActivityLog::query()
        ->where('log_name', 'call')
        ->where('event', 'recording_accessed')
        ->where('subject_id', $call->id)
        ->count());
}

it('streams a recording inline to a permitted reader of the owning client', function () {
    Storage::fake('recordings');
    $tenant = Tenant::factory()->create();
    $tl = clientUserWithRole($tenant, RoleName::TeamLeader->value);
    $call = recordedCall($tenant);
    putRecordingFor($call);

    $this->actingAs($tl)
        ->get(route('calls.recording', $call))
        ->assertOk()
        ->assertHeader('content-type', 'audio/mpeg');
});

it('serves a download with an attachment filename when asked', function () {
    Storage::fake('recordings');
    $tenant = Tenant::factory()->create();
    $tl = clientUserWithRole($tenant, RoleName::TeamLeader->value);
    $call = recordedCall($tenant);
    putRecordingFor($call);

    $this->actingAs($tl)
        ->get(route('calls.recording', ['record' => $call, 'download' => 1]))
        ->assertOk()
        ->assertDownload("call-{$call->id}.mp3");
});

it('hides another client\'s recording behind a 404 (the tenant wall)', function () {
    Storage::fake('recordings');
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $tl = clientUserWithRole($tenantA, RoleName::TeamLeader->value);
    $callB = recordedCall($tenantB);
    putRecordingFor($callB);

    $this->actingAs($tl)
        ->get(route('calls.recording', $callB))
        ->assertNotFound();
});

it('forbids an agent of the owning client (no auditor role)', function () {
    Storage::fake('recordings');
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $call = recordedCall($tenant);
    putRecordingFor($call);

    $this->actingAs($agent)
        ->get(route('calls.recording', $call))
        ->assertForbidden();
});

// --- MD-1 / MD-4: the own-call exception — an agent plays (never downloads) their own calls ---

it('streams an agent\'s own call to them and audits the listen (MD-1)', function () {
    Storage::fake('recordings');
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $call = TenantContext::run($tenant->id, fn (): Call => Call::factory()->withRecording()->forAgent($agent)->create());
    putRecordingFor($call);

    $this->actingAs($agent)
        ->get(route('calls.recording', $call))
        ->assertOk()
        ->assertHeader('content-type', 'audio/mpeg');

    expect(recordingAccessCount($call, $tenant))->toBe(1);
});

it('forbids an agent from a colleague\'s call in the same client (MD-1)', function () {
    Storage::fake('recordings');
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $colleague = clientUserWithRole($tenant, RoleName::Agent->value);
    $call = TenantContext::run($tenant->id, fn (): Call => Call::factory()->withRecording()->forAgent($colleague)->create());
    putRecordingFor($call);

    $this->actingAs($agent)
        ->get(route('calls.recording', $call))
        ->assertForbidden();
});

it('forbids an agent from downloading even their own call (MD-4 — play only)', function () {
    Storage::fake('recordings');
    $tenant = Tenant::factory()->create();
    $agent = clientUserWithRole($tenant, RoleName::Agent->value);
    $call = TenantContext::run($tenant->id, fn (): Call => Call::factory()->withRecording()->forAgent($agent)->create());
    putRecordingFor($call);

    $this->actingAs($agent)
        ->get(route('calls.recording', ['record' => $call, 'download' => 1]))
        ->assertForbidden();

    // Denied before the audit write — a refused download leaves no access entry.
    expect(recordingAccessCount($call, $tenant))->toBe(0);
});

it('404s when the call has no recording', function () {
    $tenant = Tenant::factory()->create();
    $tl = clientUserWithRole($tenant, RoleName::TeamLeader->value);
    $call = TenantContext::run($tenant->id, fn (): Call => Call::factory()->create()); // no recording

    $this->actingAs($tl)
        ->get(route('calls.recording', $call))
        ->assertNotFound();
});

it('does not serve a recording to a guest', function () {
    Storage::fake('recordings');
    $tenant = Tenant::factory()->create();
    $call = recordedCall($tenant);
    putRecordingFor($call);

    $response = $this->get(route('calls.recording', $call));

    expect($response->status())->toBeIn([302, 401, 403]);
});

it('advertises range support on a no-range inline play (HD-2)', function () {
    Storage::fake('recordings');
    $tenant = Tenant::factory()->create();
    $tl = clientUserWithRole($tenant, RoleName::TeamLeader->value);
    $call = recordedCall($tenant);
    putLargeRecordingFor($call);

    $this->actingAs($tl)
        ->get(route('calls.recording', $call))
        ->assertOk()
        ->assertHeader('content-type', 'audio/mpeg')
        ->assertHeader('accept-ranges', 'bytes');
});

it('answers a mid-file range request with 206 partial content (HD-2 — seek + Safari)', function () {
    Storage::fake('recordings');
    $tenant = Tenant::factory()->create();
    $tl = clientUserWithRole($tenant, RoleName::TeamLeader->value);
    $call = recordedCall($tenant);
    putLargeRecordingFor($call);

    $this->actingAs($tl)
        ->get(route('calls.recording', $call), ['Range' => 'bytes=5000-5099'])
        ->assertStatus(206)
        ->assertHeader('content-range', 'bytes 5000-5099/10000')
        ->assertHeader('content-length', '100');
});

it('audits a listen-start (no range) as exactly one play entry (HD-3)', function () {
    Storage::fake('recordings');
    $tenant = Tenant::factory()->create();
    $tl = clientUserWithRole($tenant, RoleName::TeamLeader->value);
    $call = recordedCall($tenant);
    putLargeRecordingFor($call);

    $this->actingAs($tl)->get(route('calls.recording', $call))->assertOk();

    expect(recordingAccessCount($call, $tenant))->toBe(1);
});

it('audits a range starting at byte 0 as a listen-start (HD-3 — Safari probe)', function () {
    Storage::fake('recordings');
    $tenant = Tenant::factory()->create();
    $tl = clientUserWithRole($tenant, RoleName::TeamLeader->value);
    $call = recordedCall($tenant);
    putLargeRecordingFor($call);

    $this->actingAs($tl)
        ->get(route('calls.recording', $call), ['Range' => 'bytes=0-1'])
        ->assertStatus(206);

    expect(recordingAccessCount($call, $tenant))->toBe(1);
});

it('does not audit a mid-file seek continuation (HD-3)', function () {
    Storage::fake('recordings');
    $tenant = Tenant::factory()->create();
    $tl = clientUserWithRole($tenant, RoleName::TeamLeader->value);
    $call = recordedCall($tenant);
    putLargeRecordingFor($call);

    $this->actingAs($tl)
        ->get(route('calls.recording', $call), ['Range' => 'bytes=5000-'])
        ->assertStatus(206);

    expect(recordingAccessCount($call, $tenant))->toBe(0);
});

it('audits a download as exactly one entry (HD-3)', function () {
    Storage::fake('recordings');
    $tenant = Tenant::factory()->create();
    $tl = clientUserWithRole($tenant, RoleName::TeamLeader->value);
    $call = recordedCall($tenant);
    putLargeRecordingFor($call);

    $this->actingAs($tl)
        ->get(route('calls.recording', ['record' => $call, 'download' => 1]))
        ->assertOk();

    expect(recordingAccessCount($call, $tenant))->toBe(1);

    TenantContext::run($tenant->id, function () use ($call) {
        $entry = ActivityLog::query()
            ->where('event', 'recording_accessed')
            ->where('subject_id', $call->id)
            ->latest('id')
            ->first();

        expect($entry->properties['mode'] ?? null)->toBe('download');
    });
});

it('audits each recording access as a PII-access trail (CR-5)', function () {
    Storage::fake('recordings');
    $tenant = Tenant::factory()->create();
    $tl = clientUserWithRole($tenant, RoleName::TeamLeader->value);
    $call = recordedCall($tenant);
    putRecordingFor($call);

    $this->actingAs($tl)->get(route('calls.recording', $call))->assertOk();

    TenantContext::run($tenant->id, function () use ($call, $tl) {
        $entry = ActivityLog::query()
            ->where('log_name', 'call')
            ->where('event', 'recording_accessed')
            ->where('subject_id', $call->id)
            ->latest('id')
            ->first();

        expect($entry)->not->toBeNull()
            ->and($entry->causer_id)->toBe($tl->id)
            ->and($entry->properties['mode'] ?? null)->toBe('play');
    });
});
