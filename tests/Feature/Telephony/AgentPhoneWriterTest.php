<?php

use App\Models\User;
use App\Telephony\AgentPhoneWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    createAsteriskPhoneTables();
    $this->writer = app(AgentPhoneWriter::class);
});

/** The three rows that make up one working phone. */
function phoneRowsFor(string $extension): array
{
    return [
        'endpoint' => DB::table('asterisk.ps_endpoints')->where('id', $extension)->first(),
        'auth' => DB::table('asterisk.ps_auths')->where('id', 'auth'.$extension)->first(),
        'aor' => DB::table('asterisk.ps_aors')->where('id', $extension)->first(),
    ];
}

// --- PP-5: one writer, three rows, written together ---

it('gives a user a phone and records the extension on their own row', function () {
    $user = User::factory()->create();

    $extension = $this->writer->provisionFor($user);
    $rows = phoneRowsFor($extension);

    expect($user->fresh()->sip_extension)->toBe($extension)
        ->and($rows['endpoint'])->not->toBeNull()
        ->and($rows['auth'])->not->toBeNull()
        ->and($rows['aor'])->not->toBeNull()
        ->and($rows['auth']->password)->not->toBeEmpty();
});

// --- PP-7: the rows mirror the hand-written 1003, field for field ---

it('writes the same settings the hand-written phone 1003 uses', function () {
    $extension = $this->writer->provisionFor(User::factory()->create());
    $rows = phoneRowsFor($extension);

    expect((array) $rows['endpoint'])->toBe([
        'id' => $extension,
        'context' => 'internal',
        'disallow' => 'all',
        'allow' => 'ulaw',
        'auth' => 'auth'.$extension,
        'aors' => $extension,
        'webrtc' => 'yes',
    ]);

    expect($rows['auth']->auth_type)->toBe('userpass')
        ->and($rows['auth']->username)->toBe($extension)
        ->and($rows['aor']->max_contacts)->toBe(1)
        ->and($rows['aor']->remove_existing)->toBe('yes');
});

// --- PP-5: idempotent — a second call is not a second phone, nor a new key ---

it('changes nothing when called a second time for the same user', function () {
    $user = User::factory()->create();

    $first = $this->writer->provisionFor($user);
    $keyBefore = phoneRowsFor($first)['auth']->password;

    $second = $this->writer->provisionFor($user->fresh());

    expect($second)->toBe($first)
        ->and(phoneRowsFor($first)['auth']->password)->toBe($keyBefore)
        ->and(DB::table('asterisk.ps_endpoints')->count())->toBe(1)
        ->and(DB::table('asterisk.ps_auths')->count())->toBe(1)
        ->and(DB::table('asterisk.ps_aors')->count())->toBe(1);
});

// --- PP-6: the pool starts at 1100, above the hand-written block, and never reuses ---

it('hands out the first number from 1100', function () {
    expect($this->writer->provisionFor(User::factory()->create()))->toBe('1100');
});

it('starts at 1100 even when the legacy file extensions are already on user rows', function () {
    User::factory()->create(['sip_extension' => '1003']);
    User::factory()->create(['sip_extension' => '1004']);

    expect($this->writer->provisionFor(User::factory()->create()))->toBe('1100');
});

it('gives the next agent the next number up', function () {
    $this->writer->provisionFor(User::factory()->create());

    expect($this->writer->provisionFor(User::factory()->create()))->toBe('1101');
});

it('never hands out a number that is retired against someone who lost their key', function () {
    $leaver = User::factory()->create();
    $retired = $this->writer->provisionFor($leaver);

    $this->writer->retireFor($leaver->fresh());

    expect($this->writer->provisionFor(User::factory()->create()))->not->toBe($retired);
});

// --- PP-19 reversibility: re-issuing is this same writer, with a fresh key ---

it('mints a new key when re-issuing a phone whose key was destroyed', function () {
    $user = User::factory()->create();
    $extension = $this->writer->provisionFor($user);
    $oldKey = phoneRowsFor($extension)['auth']->password;

    $this->writer->retireFor($user->fresh());

    $reissued = $this->writer->provisionFor($user->fresh());
    $newKey = phoneRowsFor($extension)['auth']->password;

    expect($reissued)->toBe($extension)
        ->and($newKey)->not->toBeEmpty()
        ->and($newKey)->not->toBe($oldKey);
});

// --- PP-5: two agents never hold the same key ---

it('gives two agents two different keys', function () {
    $first = $this->writer->provisionFor(User::factory()->create());
    $second = $this->writer->provisionFor(User::factory()->create());

    expect(phoneRowsFor($first)['auth']->password)
        ->not->toBe(phoneRowsFor($second)['auth']->password);
});

// --- PP-19: retiring destroys the key and keeps the number ---

it('destroys the key and leaves the number standing', function () {
    $user = User::factory()->create();
    $extension = $this->writer->provisionFor($user);

    $this->writer->retireFor($user->fresh());
    $rows = phoneRowsFor($extension);

    expect($rows['auth'])->toBeNull()
        ->and($user->fresh()->sip_extension)->toBe($extension)
        ->and($rows['endpoint'])->not->toBeNull()
        ->and($rows['aor'])->not->toBeNull();
});

it('does nothing when the person never had a phone', function () {
    $user = User::factory()->create();

    $this->writer->retireFor($user);

    expect($user->fresh()->sip_extension)->toBeNull()
        ->and(DB::table('asterisk.ps_auths')->count())->toBe(0);
});
