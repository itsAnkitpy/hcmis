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
