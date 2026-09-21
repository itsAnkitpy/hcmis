<?php

use App\Enums\RoleName;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

/**
 * inbound-audio slice 3, step 4 (AU-12 / AUQ-4) — the one way a client's hold music
 * leaves the server. The voice box fetches it with no login, so the signed address IS
 * the wall; these prove it holds and that the two headers Asterisk actually reads are
 * on the response.
 */
beforeEach(function () {
    config()->set('telephony.media.disk', 'local');
    config()->set('telephony.media.fetch_ips', []);
    Storage::fake('local');
});

/** A client whose converted music is really on the disk. */
function clientWithHoldMusic(string $bytes = 'FAKE-WAV-BYTES'): Tenant
{
    $tenant = Tenant::factory()->create();
    $path = "hold-music/{$tenant->id}/".hash('sha256', $bytes).'.wav';

    Storage::disk('local')->put($path, $bytes);
    $tenant->update(['hold_music_path' => $path]);

    return $tenant->fresh();
}

// --- the happy path, and the two headers the voice box reads ---

it('serves the client\'s music to a signed request', function () {
    $tenant = clientWithHoldMusic();

    $this->get($tenant->holdMusicUrl())
        ->assertOk()
        ->assertHeader('Content-Type', 'audio/wav');
});

it('sends a year-long cache header, so the voice box stops re-downloading (S165)', function () {
    // Without this the box fetches the file again on EVERY hold start: Laravel sends no
    // ETag of its own, so there is nothing else for it to revalidate against. The
    // assertion is here rather than in a comment because the session middleware on this
    // route is exactly the kind of thing that would quietly replace the header.
    $tenant = clientWithHoldMusic();

    $response = $this->get($tenant->holdMusicUrl());

    expect($response->headers->get('Cache-Control'))->toContain('max-age=31536000');
});

// --- the wall: the signature covers the whole address ---

it('refuses a request with no signature at all', function () {
    $tenant = clientWithHoldMusic();
    $unsigned = strtok((string) $tenant->holdMusicUrl(), '?');

    $this->get($unsigned)->assertForbidden();
});

it('refuses one client\'s signed address with another client\'s id swapped in', function () {
    $mine = clientWithHoldMusic('MY-MUSIC');
    $theirs = clientWithHoldMusic('THEIR-MUSIC');

    $tampered = str_replace(
        "/hold-music/{$mine->id}/",
        "/hold-music/{$theirs->id}/",
        (string) $mine->holdMusicUrl(),
    );

    $this->get($tampered)->assertForbidden();
});

it('refuses one client\'s signed address with another client\'s file swapped in', function () {
    $mine = clientWithHoldMusic('MY-MUSIC');
    $theirs = clientWithHoldMusic('THEIR-MUSIC');

    $tampered = str_replace(
        basename((string) $mine->hold_music_path),
        basename((string) $theirs->hold_music_path),
        (string) $mine->holdMusicUrl(),
    );

    $this->get($tampered)->assertForbidden();
});

it('serves each client their own file and never the other\'s', function () {
    $one = clientWithHoldMusic('MUSIC-ONE');
    $two = clientWithHoldMusic('MUSIC-TWO');

    expect($this->get($one->holdMusicUrl())->streamedContent())->toBe('MUSIC-ONE')
        ->and($this->get($two->holdMusicUrl())->streamedContent())->toBe('MUSIC-TWO');
});

// --- content naming is what makes a year-long cache safe ---

it('stops serving a replaced file, even though its address is still correctly signed', function () {
    $tenant = clientWithHoldMusic('OLD-MUSIC');
    $oldAddress = (string) $tenant->holdMusicUrl();

    $newPath = "hold-music/{$tenant->id}/".hash('sha256', 'NEW-MUSIC').'.wav';
    Storage::disk('local')->put($newPath, 'NEW-MUSIC');
    $tenant->update(['hold_music_path' => $newPath]);

    $this->get($oldAddress)->assertNotFound();
});

it('gives a client with no uploaded music nothing to fetch', function () {
    $tenant = clientWithHoldMusic();
    $address = (string) $tenant->holdMusicUrl();

    $tenant->update(['hold_music_path' => null]);

    $this->get($address)->assertNotFound();
});

// --- guard 2: who may fetch ---

it('lets the voice box through on its configured address', function () {
    $tenant = clientWithHoldMusic();
    config()->set('telephony.media.fetch_ips', ['216.48.185.111']);

    $this->withServerVariables(['REMOTE_ADDR' => '216.48.185.111'])
        ->get($tenant->holdMusicUrl())
        ->assertOk();
});

it('turns away a stranger once the voice box\'s address is configured', function () {
    $tenant = clientWithHoldMusic();
    config()->set('telephony.media.fetch_ips', ['216.48.185.111']);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->get($tenant->holdMusicUrl())
        ->assertForbidden();
});

it('lets head office listen to the upload from anywhere, which is the form\'s play button (AU-9)', function () {
    $tenant = clientWithHoldMusic();
    config()->set('telephony.media.fetch_ips', ['216.48.185.111']);

    $this->actingAs(reportsHcUser(RoleName::HcAdmin->value))
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->get($tenant->holdMusicUrl())
        ->assertOk();
});

it('does not let a client-side team leader fetch it (AU-8 is head office only)', function () {
    $tenant = clientWithHoldMusic();
    config()->set('telephony.media.fetch_ips', ['216.48.185.111']);

    $this->actingAs(clientUserWithRole($tenant, RoleName::TeamLeader->value))
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->get($tenant->holdMusicUrl())
        ->assertForbidden();
});

// --- slice 4: the same route, a second sound ---

/** A client whose converted closed message is really on the disk. */
function clientWithClosedMessage(string $bytes = 'FAKE-SPEECH-BYTES'): Tenant
{
    $tenant = Tenant::factory()->create();
    $path = "closed-message/{$tenant->id}/".hash('sha256', $bytes).'.wav';

    Storage::disk('local')->put($path, $bytes);
    $tenant->update(['closed_message_path' => $path]);

    return $tenant->fresh();
}

it('serves a client\'s closed message on the same route, with the same two headers', function () {
    $tenant = clientWithClosedMessage();

    $response = $this->get($tenant->closedMessageUrl());

    $response->assertOk()->assertHeader('Content-Type', 'audio/wav');
    expect($response->headers->get('Cache-Control'))->toContain('max-age=31536000')
        ->and($response->streamedContent())->toBe('FAKE-SPEECH-BYTES');
});

it('🔴 leaves hold music\'s address EXACTLY where slice 3 shipped it', function () {
    // This is the whole reason the kind is the FIRST segment. The address is stored in
    // Asterisk's musiconhold_entry row and cached by the voice box for a year, and that
    // row is only rewritten on the next upload — so if this path ever moves, every
    // client's waiting music goes silent on staging until someone re-uploads it, with
    // nothing in the app saying why.
    $tenant = clientWithHoldMusic();

    expect(parse_url((string) $tenant->holdMusicUrl(), PHP_URL_PATH))
        ->toBe("/hold-music/{$tenant->id}/".basename((string) $tenant->hold_music_path));
});

it('refuses a signed music address with the sound\'s kind swapped for another', function () {
    // The signature covers the whole address, path segments included — so the kind
    // cannot be edited to reach a file the client holds under a different setting.
    $tenant = clientWithHoldMusic();

    $tampered = str_replace('/hold-music/', '/closed-message/', (string) $tenant->holdMusicUrl());

    $this->get($tampered)->assertForbidden();
});

it('does not serve one sound\'s file through the other sound\'s address', function () {
    $tenant = clientWithHoldMusic('MUSIC');
    $messagePath = "closed-message/{$tenant->id}/".hash('sha256', 'SPEECH').'.wav';
    Storage::disk('local')->put($messagePath, 'SPEECH');
    $tenant->update(['closed_message_path' => $messagePath]);

    // A correctly signed CLOSED-MESSAGE address carrying the MUSIC file's hash: signed,
    // so it gets past the wall, and then refused because that is not the message.
    $address = URL::signedRoute('tenants.media', [
        'kind' => 'closed-message',
        'tenant' => $tenant->id,
        'hash' => basename((string) $tenant->hold_music_path, '.wav'),
    ]);

    $this->get($address)->assertNotFound();
});

it('gives a client with no closed message nothing to fetch', function () {
    $tenant = clientWithClosedMessage();
    $address = (string) $tenant->closedMessageUrl();

    $tenant->update(['closed_message_path' => null]);

    $this->get($address)->assertNotFound();
});
