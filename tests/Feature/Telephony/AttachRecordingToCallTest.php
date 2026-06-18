<?php

use App\Events\Telephony\RecordingReady;
use App\Listeners\AttachRecordingToCall;
use App\Models\Call;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

afterEach(function () {
    TenantContext::forget();
});

/**
 * CP-B3-2 — the queued RecordingReady listener attaches the recording to its calls
 * row BY UUID (D3). The listener runs with no ambient tenant (the queue worker's
 * reality), discovers the tenant from the row globally, then stamps scoped to it.
 * The merge-vs-wrap-up race is handled with bounded retry.
 */

/**
 * A listener with a mocked queue job so attempts()/release() are observable —
 * the null-tenant retry/orphan branches need it; the success path does not.
 *
 * @return array{0: AttachRecordingToCall, 1: MockInterface&Job}
 */
function listenerWithJob(int $attempts): array
{
    $listener = new AttachRecordingToCall;
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('attempts')->andReturn($attempts);
    $listener->setJob($job);

    return [$listener, $job];
}

function recordingReadyFor(string $callId): RecordingReady
{
    return new RecordingReady($callId, 'call-1', 'recordings', 'recordings/call-1.mp3');
}

it('stamps the recording on the matching row in the row’s tenant context (no ambient tenant)', function () {
    $tenant = Tenant::factory()->create();
    $uuid = (string) Str::uuid();

    TenantContext::run($tenant->id, fn () => Call::factory()->create([
        'correlation_id' => $uuid,
        'recording_disk' => null,
        'recording_path' => null,
    ]));

    // The worker has no tenant set — the listener discovers it from the row.
    (new AttachRecordingToCall)->handle(recordingReadyFor($uuid));

    $call = TenantContext::run($tenant->id, fn (): ?Call => Call::query()->where('correlation_id', $uuid)->first());

    expect($call->recording_disk)->toBe('recordings')
        ->and($call->recording_path)->toBe('recordings/call-1.mp3');
});

it('releases for a bounded retry when the row is not written yet (the race)', function () {
    $uuid = (string) Str::uuid();

    [$listener, $job] = listenerWithJob(attempts: 1);
    $job->shouldReceive('release')->once()->with(5);

    // No row exists yet (the merge beat the wrap-up) — the listener must retry.
    $listener->handle(recordingReadyFor($uuid));
});

it('gives up gracefully — logs, does not retry — once the bound is reached (orphan)', function () {
    $uuid = (string) Str::uuid();

    Log::shouldReceive('warning')->once();

    [$listener, $job] = listenerWithJob(attempts: 12); // at the tries bound
    $job->shouldNotReceive('release');

    $listener->handle(recordingReadyFor($uuid)); // reaches here without throwing
});

it('skips a non-UUID callId without retrying (inbound / lab recording, O1)', function () {
    [$listener, $job] = listenerWithJob(attempts: 1);
    $job->shouldNotReceive('release'); // the guard returns before the retry path

    $listener->handle(recordingReadyFor('1718999999.42')); // a raw Asterisk leg id
});
