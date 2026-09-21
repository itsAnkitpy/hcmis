<?php

use App\Jobs\ConvertHoldMusicJob;
use App\Models\Tenant;
use App\Telephony\HoldMusicWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * inbound-audio slice 3, steps 2, 3 and 5 — the queued half. sox is faked; the file
 * plumbing and the Asterisk rows are real, the same shape MergeCallRecordingJobTest
 * uses for the recording merge.
 */
beforeEach(function () {
    createAsteriskMusicTables();
    config()->set('telephony.hold_music.disk', 'local');
    Storage::fake('local');
});

/** Stand in for sox: write the output file its command line names. */
function fakeSox(string $bytes = 'CONVERTED-WAV'): void
{
    Process::fake(function (PendingProcess $process) use ($bytes) {
        $command = $process->command;
        file_put_contents(end($command), $bytes);

        return Process::result();
    });
}

function runConversionFor(Tenant $tenant, string $uploadPath = 'hold-music/pending/1/ab12cd34/tune.mp3'): void
{
    Storage::disk('local')->put($uploadPath, 'RAW-MP3-BYTES');

    (new ConvertHoldMusicJob($tenant, 'local', $uploadPath))->handle(app(HoldMusicWriter::class));
}

it('converts the upload, names the file by its content and points the client at it', function () {
    fakeSox('CONVERTED-WAV');
    $tenant = Tenant::factory()->create();

    runConversionFor($tenant);

    $expected = "hold-music/{$tenant->id}/".hash('sha256', 'CONVERTED-WAV').'.wav';

    expect($tenant->fresh()->hold_music_path)->toBe($expected)
        ->and(Storage::disk('local')->get($expected))->toBe('CONVERTED-WAV');
});

it('asks sox for 8 kHz mono 16-bit, and for NO trailing silence', function () {
    // The 1 s of end padding S165 found belongs to slice 4's spoken message. Hold music
    // LOOPS, so a second of silence would be a hiccup on every lap.
    fakeSox();
    runConversionFor(Tenant::factory()->create());

    Process::assertRan(function (PendingProcess $process): bool {
        $command = $process->command;

        return $command[0] === 'sox'
            && in_array('8000', $command, true)
            && $command[array_search('-c', $command, true) + 1] === '1'
            && $command[array_search('-b', $command, true) + 1] === '16'
            && ! in_array('pad', $command, true);
    });
});

it('keeps the uploaded file\'s extension, because that is how sox knows what it is reading', function () {
    fakeSox();
    runConversionFor(Tenant::factory()->create(), 'hold-music/pending/1/aabbccdd/jingle.wav');

    Process::assertRan(fn (PendingProcess $process): bool => str_ends_with($process->command[1], '.wav'));
});

it('tells the voice box where to find it (AU-13)', function () {
    fakeSox();
    $tenant = Tenant::factory()->create();

    runConversionFor($tenant);

    $class = DB::table('asterisk.musiconhold')->where('name', 'tenant-'.$tenant->id)->first();
    $entry = DB::table('asterisk.musiconhold_entry')->where('name', 'tenant-'.$tenant->id)->first();

    expect($class?->mode)->toBe('playlist')
        ->and($entry?->entry)->toBe($tenant->fresh()->holdMusicUrl());
});

it('clears away the raw upload and the music it replaced', function () {
    fakeSox('FIRST-WAV');
    $tenant = Tenant::factory()->create();
    runConversionFor($tenant, 'hold-music/pending/1/first/one.mp3');
    $firstPath = (string) $tenant->fresh()->hold_music_path;

    fakeSox('SECOND-WAV');
    runConversionFor($tenant->fresh(), 'hold-music/pending/1/second/two.mp3');

    expect(Storage::disk('local')->exists($firstPath))->toBeFalse()
        ->and(Storage::disk('local')->exists('hold-music/pending/1/second/two.mp3'))->toBeFalse()
        ->and(Storage::disk('local')->exists((string) $tenant->fresh()->hold_music_path))->toBeTrue();
});

it('leaves the client\'s previous music playing when the conversion fails', function () {
    fakeSox('GOOD-WAV');
    $tenant = Tenant::factory()->create();
    runConversionFor($tenant, 'hold-music/pending/1/good/one.mp3');
    $goodPath = (string) $tenant->fresh()->hold_music_path;

    Process::fake(fn () => Process::result(exitCode: 1, errorOutput: 'sox: no handler for file extension'));

    expect(fn () => runConversionFor($tenant->fresh(), 'hold-music/pending/1/bad/two.xyz'))
        ->toThrow(ProcessFailedException::class);

    expect($tenant->fresh()->hold_music_path)->toBe($goodPath)
        ->and(Storage::disk('local')->exists($goodPath))->toBeTrue();
});
