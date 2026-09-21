<?php

use App\Models\Tenant;
use App\Telephony\HoldMusicWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * inbound-audio slice 3, step 5 — the two rows the voice box reads a client's own
 * waiting-area music from. Written against a copy of Asterisk's real tables, built
 * from the shapes read off staging on 2026-09-21.
 */
beforeEach(function () {
    createAsteriskMusicTables();
    $this->writer = app(HoldMusicWriter::class);
});

function holdMusicRowsFor(Tenant $tenant): array
{
    return [
        'class' => DB::table('asterisk.musiconhold')->where('name', 'tenant-'.$tenant->id)->first(),
        'entries' => DB::table('asterisk.musiconhold_entry')->where('name', 'tenant-'.$tenant->id)->get(),
    ];
}

it('writes the class and its one playlist entry together', function () {
    $tenant = Tenant::factory()->create(['hold_music_path' => 'hold-music/1/'.str_repeat('a', 64).'.wav']);

    $this->writer->writeFor($tenant);
    $rows = holdMusicRowsFor($tenant);

    expect($rows['class'])->not->toBeNull()
        ->and($rows['class']->mode)->toBe('playlist')
        ->and($rows['entries'])->toHaveCount(1)
        ->and($rows['entries'][0]->position)->toBe(0)
        ->and($rows['entries'][0]->entry)->toBe($tenant->holdMusicUrl());
});

it('writes a web address, because a playlist entry must be one (or an absolute path)', function () {
    $tenant = Tenant::factory()->create(['hold_music_path' => 'hold-music/1/'.str_repeat('b', 64).'.wav']);

    $this->writer->writeFor($tenant);
    $entry = holdMusicRowsFor($tenant)['entries'][0]->entry;

    expect($entry)->toStartWith('http')
        ->and($entry)->toContain('.wav')
        ->and($entry)->toContain('signature=')
        // The column is varchar(1024) on the box. A signed address is nowhere near it,
        // which is why AUQ-4 needs no token table to keep the address short.
        ->and(strlen($entry))->toBeLessThan(1024);
});

it('writes nothing at all for a client who has uploaded no music (AU-13)', function () {
    $tenant = Tenant::factory()->create(['hold_music_path' => null]);

    $this->writer->writeFor($tenant);

    expect(DB::table('asterisk.musiconhold')->count())->toBe(0)
        ->and(DB::table('asterisk.musiconhold_entry')->count())->toBe(0);
});

it('replaces the entry on a re-upload instead of stacking a second one', function () {
    $tenant = Tenant::factory()->create(['hold_music_path' => 'hold-music/1/'.str_repeat('c', 64).'.wav']);
    $this->writer->writeFor($tenant);
    $first = holdMusicRowsFor($tenant)['entries'][0]->entry;

    $tenant->update(['hold_music_path' => 'hold-music/1/'.str_repeat('d', 64).'.wav']);
    $this->writer->writeFor($tenant->fresh());
    $rows = holdMusicRowsFor($tenant);

    expect(DB::table('asterisk.musiconhold')->count())->toBe(1)
        ->and($rows['entries'])->toHaveCount(1)
        ->and($rows['entries'][0]->entry)->not->toBe($first);
});

it('keeps one class per client and never crosses two clients', function () {
    $one = Tenant::factory()->create(['hold_music_path' => 'hold-music/1/'.str_repeat('e', 64).'.wav']);
    $two = Tenant::factory()->create(['hold_music_path' => 'hold-music/2/'.str_repeat('f', 64).'.wav']);

    $this->writer->writeFor($one);
    $this->writer->writeFor($two);

    expect(DB::table('asterisk.musiconhold')->count())->toBe(2)
        ->and(holdMusicRowsFor($one)['entries'][0]->entry)
        ->not->toBe(holdMusicRowsFor($two)['entries'][0]->entry);
});
