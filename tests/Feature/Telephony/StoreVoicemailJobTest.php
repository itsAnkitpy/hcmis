<?php

use App\Events\Telephony\RecordingReady;
use App\Jobs\StoreVoicemailJob;
use App\Telephony\TelephonyProvider;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * inbound-audio slice 8, step 3 — the message's one WAV fetched, compressed and
 * announced under the call's ticket, so AttachRecordingToCall puts it on the missed row.
 * The provider and sox are faked; the file plumbing is real (the merge job's shape).
 */
beforeEach(function () {
    config()->set('telephony.recordings.disk', 'local');
    Storage::fake('local');
});

it('fetches the message, compresses it to mp3, stores it and announces it under the ticket', function () {
    Event::fake([RecordingReady::class]);

    $this->mock(TelephonyProvider::class, function ($mock) {
        $mock->shouldReceive('fetchRecording')->with('voicemail-ticket-1')->once()->andReturn('WAV-BYTES');
    });

    Process::fake(function (PendingProcess $process) {
        $command = $process->command;
        file_put_contents(end($command), 'MP3-BYTES');

        return Process::result();
    });

    (new StoreVoicemailJob('ticket-1', 'voicemail-ticket-1'))->handle(app(TelephonyProvider::class));

    Process::assertRan(fn (PendingProcess $process): bool => $process->command[0] === 'sox'
        && str_ends_with($process->command[1], 'voicemail-ticket-1.wav')
        && str_ends_with(end($process->command), 'voicemail-ticket-1.mp3'));

    expect(Storage::disk('local')->get('recordings/voicemail-ticket-1.mp3'))->toBe('MP3-BYTES');

    Event::assertDispatched(RecordingReady::class, fn (RecordingReady $event): bool => $event->callId === 'ticket-1'
        && $event->disk === 'local'
        && $event->path === 'recordings/voicemail-ticket-1.mp3');
});

it('cleans its temp files up even when the conversion fails', function () {
    $this->mock(TelephonyProvider::class, function ($mock) {
        $mock->shouldReceive('fetchRecording')->andReturn('WAV-BYTES');
    });

    Process::fake(fn () => Process::result(exitCode: 1));

    expect(fn () => (new StoreVoicemailJob('ticket-1', 'voicemail-ticket-2'))->handle(app(TelephonyProvider::class)))
        ->toThrow(Exception::class);

    expect(file_exists(sys_get_temp_dir().'/voicemail-ticket-2.wav'))->toBeFalse();
    Storage::disk('local')->assertMissing('recordings/voicemail-ticket-2.mp3');
});

it('rides out a voice box that is briefly unreachable before giving up (review S175 F5)', function () {
    // The worker's own default is no wait between tries (queue:work --backoff=0), so all
    // three production tries could fail inside one Asterisk reload.
    $job = new StoreVoicemailJob('ticket-1', 'voicemail-ticket-1');

    expect($job->tries)->toBe(5)
        ->and($job->backoff)->toBe([10, 30, 60, 120]);
});

it('logs a message it could not store, naming the ticket and the way back', function () {
    Log::spy();

    (new StoreVoicemailJob('ticket-1', 'voicemail-ticket-1'))->failed(new RuntimeException('HTTP 503'));

    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context): bool => $context['ticket'] === 'ticket-1'
        && $context['recording'] === 'voicemail-ticket-1'
        && $context['error'] === 'HTTP 503');
});
