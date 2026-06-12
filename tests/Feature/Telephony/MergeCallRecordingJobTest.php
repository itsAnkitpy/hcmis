<?php

use App\Events\Telephony\RecordingFailed;
use App\Events\Telephony\RecordingReady;
use App\Jobs\MergeCallRecordingJob;
use App\Telephony\TelephonyProvider;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * The queued half of D4: fetch the two per-side WAVs off the voice box,
 * sox-merge to the stereo MP3 (caller left, heard right), store it, announce
 * it. The provider and sox are faked; the file plumbing is real.
 */
beforeEach(function () {
    config()->set('telephony.recordings.disk', 'local');
    Storage::fake('local');
});

it('merges both sides into a stereo mp3 and announces it ready', function () {
    Event::fake([RecordingReady::class]);

    $this->mock(TelephonyProvider::class, function ($mock) {
        $mock->shouldReceive('fetchRecording')->with('call-1-said')->once()->andReturn('SAID-WAV');
        $mock->shouldReceive('fetchRecording')->with('call-1-heard')->once()->andReturn('HEARD-WAV');
    });

    // Stand in for sox: write the output file its command line names, the
    // way the real binary would.
    Process::fake(function (PendingProcess $process) {
        $command = $process->command;
        file_put_contents(end($command), 'MP3-BYTES');

        return Process::result();
    });

    (new MergeCallRecordingJob('leg-caller', 'call-1'))->handle(app(TelephonyProvider::class));

    Process::assertRan(function (PendingProcess $process): bool {
        [$binary, $mode, $left, $right] = $process->command;

        return $binary === 'sox'
            && $mode === '-M'
            && str_ends_with($left, 'call-1-said.wav')      // left channel = the caller's voice (PW5-4)
            && str_ends_with($right, 'call-1-heard.wav');
    });

    Storage::disk('local')->assertExists('recordings/call-1.mp3');
    expect(Storage::disk('local')->get('recordings/call-1.mp3'))->toBe('MP3-BYTES');

    Event::assertDispatched(RecordingReady::class, fn (RecordingReady $event): bool => $event->callId === 'leg-caller'
        && $event->recordingName === 'call-1'
        && $event->disk === 'local'
        && $event->path === 'recordings/call-1.mp3');
});

it('cleans its temp files up even on success', function () {
    Event::fake([RecordingReady::class]);

    $this->mock(TelephonyProvider::class, function ($mock) {
        $mock->shouldReceive('fetchRecording')->twice()->andReturn('WAV');
    });

    Process::fake(function (PendingProcess $process) {
        $command = $process->command;
        file_put_contents(end($command), 'MP3-BYTES');

        return Process::result();
    });

    (new MergeCallRecordingJob('leg-caller', 'call-2'))->handle(app(TelephonyProvider::class));

    $directory = sys_get_temp_dir();
    expect(file_exists("{$directory}/call-2-said.wav"))->toBeFalse()
        ->and(file_exists("{$directory}/call-2-heard.wav"))->toBeFalse()
        ->and(file_exists("{$directory}/call-2.mp3"))->toBeFalse();
});

it('announces the failure when the merge falls over', function () {
    Event::fake([RecordingFailed::class]);

    (new MergeCallRecordingJob('leg-caller', 'call-3'))->failed(new RuntimeException('sox exploded'));

    Event::assertDispatched(RecordingFailed::class, fn (RecordingFailed $event): bool => $event->recordingName === 'call-3'
        && $event->reason === 'sox exploded');
});
