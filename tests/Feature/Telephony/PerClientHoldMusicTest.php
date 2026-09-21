<?php

use App\Models\Tenant;
use App\Telephony\Flows\CallToAgentFlow;
use App\Telephony\Flows\Switchboard;
use App\Telephony\TelephonyProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * inbound-audio slice 3, step 5 — which music a waiting caller is given (Q4).
 *
 * Unlike the other waiting-room tests, these need a REAL client row, because the
 * answer comes off it. The client is read once when the call arrives, alongside the
 * ring time and the hold limit, so per-client music costs no extra database trip.
 */
beforeEach(function () {
    fakeNumberDirectory();
    fakeAgentDirectory();
    fakeAgentRouter(null);          // nobody free, so the caller goes straight to waiting
    Queue::fake();
});

function arriveForClient(Tenant $tenant, TelephonyProvider $telephony): void
{
    $flow = new CallToAgentFlow($telephony, new Switchboard($telephony));
    $flow->handle(stasisStart('caller-leg', [], null, (string) $tenant->id));
}

it('asks for the client\'s own music when they have uploaded some (AU-13)', function () {
    $tenant = Tenant::factory()->create([
        'hold_music_path' => 'hold-music/9/'.str_repeat('a', 64).'.wav',
    ]);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-leg');
    $telephony->shouldReceive('startHoldMusic')->once()->with('caller-leg', 'tenant-'.$tenant->id);

    arriveForClient($tenant, $telephony);
});

it('names no music at all for a client who has uploaded none, so the stock set plays', function () {
    // Naming a class the voice box does not know would ALSO end in the default music
    // (proven S165), but only after a database lookup that misses on every hold start.
    // Sending nothing keeps today's path, where `default` is already in memory.
    $tenant = Tenant::factory()->create(['hold_music_path' => null]);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->once()->with('caller-leg');
    $telephony->shouldReceive('startHoldMusic')->once()->with('caller-leg', null);

    arriveForClient($tenant, $telephony);
});

it('gives each client their own music on the same switch', function () {
    $one = Tenant::factory()->create(['hold_music_path' => 'hold-music/1/'.str_repeat('b', 64).'.wav']);
    $two = Tenant::factory()->create(['hold_music_path' => null]);

    $telephony = Mockery::mock(TelephonyProvider::class);
    $telephony->shouldReceive('answer')->twice();
    $telephony->shouldReceive('startHoldMusic')->once()->with('caller-leg', 'tenant-'.$one->id);
    $telephony->shouldReceive('startHoldMusic')->once()->with('caller-leg', null);

    arriveForClient($one, $telephony);
    arriveForClient($two, $telephony);
});
