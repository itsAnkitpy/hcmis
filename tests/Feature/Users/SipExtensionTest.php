<?php

use App\Models\ActivityLog;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

// --- PP-1: the database refuses to give two people the same phone ---

it('refuses to store the same extension on two users', function () {
    User::factory()->create(['sip_extension' => '1100']);

    expect(fn () => User::factory()->create(['sip_extension' => '1100']))
        ->toThrow(QueryException::class);
});

it('allows any number of users with no extension', function () {
    User::factory()->count(3)->create();

    expect(User::whereNull('sip_extension')->count())->toBe(3);
});

// --- PP-2: an extension change is audited, the password never is ---

it('records an extension change without ever logging the password', function () {
    $user = User::factory()->create(['sip_extension' => '1100']);

    $activity = TenantContext::cross(function () use ($user): ?ActivityLog {
        $user->forceFill([
            'sip_extension' => '1101',
            'password' => Hash::make('super-secret-value'),
        ])->save();

        return ActivityLog::query()
            ->where('subject_id', $user->id)
            ->where('event', 'updated')
            ->latest('id')
            ->first();
    });

    $changes = $activity->attribute_changes->toArray();

    expect($changes['attributes']['sip_extension'])->toBe('1101')
        ->and($changes['old']['sip_extension'])->toBe('1100')
        ->and($changes['attributes'])->not->toHaveKey('password')
        ->and(json_encode($changes))->not->toContain('super-secret-value');
});
