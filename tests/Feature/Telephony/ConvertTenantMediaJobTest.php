<?php

use App\Enums\TenantMedia;
use App\Jobs\ConvertTenantMediaJob;
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
    config()->set('telephony.media.disk', 'local');
    Storage::fake('local');
});

/**
 * Stand in for sox: write the output file its command line names.
 *
 * The output is the LAST .wav path that is not the input — not simply the last
 * argument, because slice 4's speech padding rides on the end as `pad 0 1`, which is
 * how this helper first broke when that landed.
 */
function fakeSox(string $bytes = 'CONVERTED-WAV'): void
{
    Process::fake(function (PendingProcess $process) use ($bytes) {
        $command = $process->command;
        $paths = array_filter(
            array_slice($command, 2),
            fn (string $argument): bool => str_ends_with($argument, '.wav'),
        );

        file_put_contents(end($paths), $bytes);

        return Process::result();
    });
}

function runConversionFor(Tenant $tenant, string $uploadPath = 'hold-music/pending/1/ab12cd34/tune.mp3', TenantMedia $kind = TenantMedia::HoldMusic): void
{
    Storage::disk('local')->put($uploadPath, 'RAW-MP3-BYTES');

    (new ConvertTenantMediaJob($tenant, $kind, 'local', $uploadPath))->handle(app(HoldMusicWriter::class));
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

// --- slice 4: the same job, a spoken message ---

it('pads a spoken message with one second of silence, which music never gets', function () {
    // S165 measured a spoken greeting losing its last word on a real handset without it.
    // Verified locally against sox before this was written: `pad 0 1` appends exactly
    // 1.000 s and leaves the 8 kHz mono 16-bit format untouched.
    fakeSox();
    runConversionFor(
        Tenant::factory()->create(),
        'closed-message/pending/1/aabbccdd/closed.mp3',
        TenantMedia::ClosedMessage,
    );

    Process::assertRan(function (PendingProcess $process): bool {
        $command = $process->command;
        $pad = array_search('pad', $command, true);

        return $pad !== false
            && $command[$pad + 1] === '0'
            && $command[$pad + 2] === '1'
            // The padding rides AFTER the output file, so it is an effect on the already
            // converted audio rather than something that could change its format.
            && $pad > array_search('-b', $command, true);
    });
});

it('stores a closed message under its own kind, and points the client\'s own column at it', function () {
    fakeSox('SPOKEN-WAV');
    $tenant = Tenant::factory()->create();

    runConversionFor($tenant, 'closed-message/pending/1/aabbccdd/closed.mp3', TenantMedia::ClosedMessage);

    $expected = "closed-message/{$tenant->id}/".hash('sha256', 'SPOKEN-WAV').'.wav';

    expect($tenant->fresh()->closed_message_path)->toBe($expected)
        ->and($tenant->fresh()->hold_music_path)->toBeNull()
        ->and(Storage::disk('local')->get($expected))->toBe('SPOKEN-WAV');
});

it('writes NO row in the voice box\'s music tables for a closed message', function () {
    // Hold music needs those rows because the box looks a class up by name. A message is
    // played by a direct order carrying its address, so a row here would be a class
    // nothing ever asks for.
    fakeSox();
    $tenant = Tenant::factory()->create();

    runConversionFor($tenant, 'closed-message/pending/1/aabbccdd/closed.mp3', TenantMedia::ClosedMessage);

    expect(DB::table('asterisk.musiconhold')->count())->toBe(0)
        ->and(DB::table('asterisk.musiconhold_entry')->count())->toBe(0);
});

it('replaces a client\'s closed message without touching their music', function () {
    fakeSox('MUSIC-WAV');
    $tenant = Tenant::factory()->create();
    runConversionFor($tenant);
    $musicPath = (string) $tenant->fresh()->hold_music_path;

    fakeSox('SPOKEN-WAV');
    runConversionFor($tenant->fresh(), 'closed-message/pending/1/x/closed.mp3', TenantMedia::ClosedMessage);

    expect($tenant->fresh()->hold_music_path)->toBe($musicPath)
        ->and(Storage::disk('local')->exists($musicPath))->toBeTrue();
});
