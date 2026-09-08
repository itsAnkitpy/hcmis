<?php

use App\Models\User;
use App\Telephony\AgentDirectory;
use App\Telephony\AgentPhoneWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * SEC-1 slice 4 (PP-11/PP-12) — the agent->phone directory now reads
 * `users.sip_extension`, and neither of its two lookups falls back any more.
 *
 * 🔴 The old single-agent config is deliberately still populated below. Every
 * refusal here is asserted with `1003` and `legacy-secret` sitting right there
 * to be handed out, which is exactly what the removed fallback used to do: an
 * unmapped agent's browser registered as 1003 and their calls rang 1003.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    createAsteriskPhoneTables();

    config()->set('telephony.agent.extension', '1003');
    config()->set('telephony.agent.password', 'legacy-secret');
    config()->set('telephony.agent.endpoint', 'PJSIP/1003');
    config()->set('telephony.agent.ws_url', 'ws://127.0.0.1:8088/ws');
    config()->set('telephony.agent.sip_domain', 'asterisk.lab');
    config()->set('telephony.agent.directory', [
        7 => ['extension' => '1004', 'endpoint' => 'PJSIP/1004', 'password' => 'agent2-secret'],
    ]);

    $this->directory = app(AgentDirectory::class);
});

// --- PP-11: both lookups read the database ---

it('resolves an agent with a phone to their own dial endpoint (RD-2)', function () {
    $user = User::factory()->create();
    $extension = app(AgentPhoneWriter::class)->provisionFor($user);

    expect($this->directory->endpointFor($user->id))->toBe('PJSIP/'.$extension);
});

it('resolves an agent to their own browser identity — their extension and their key (Fold A)', function () {
    $user = User::factory()->create();
    $extension = app(AgentPhoneWriter::class)->provisionFor($user);
    $key = DB::table('asterisk.ps_auths')->where('id', 'auth'.$extension)->value('password');

    expect($this->directory->browserIdentityFor($user->id))->toBe([
        'extension' => $extension,
        'password' => $key,
        'wsUrl' => 'ws://127.0.0.1:8088/ws',
        'sipDomain' => 'asterisk.lab',
    ]);
});

// --- PP-12: the silent fallback is gone from BOTH methods ---

it('refuses a dial endpoint for an agent with no extension, rather than ringing 1003', function () {
    $user = User::factory()->create();

    expect($this->directory->endpointFor($user->id))->toBeNull();
});

it('refuses a browser identity for an agent with no extension, rather than handing out 1003', function () {
    $user = User::factory()->create();

    expect($this->directory->browserIdentityFor($user->id))->toBe([
        'extension' => null,
        'password' => null,
        'wsUrl' => 'ws://127.0.0.1:8088/ws',
        'sipDomain' => 'asterisk.lab',
    ]);
});

/**
 * A retired agent (PP-19) keeps their number forever and loses their key. Handing
 * the browser a real extension with a null password is a registration that fails at
 * the switch and reads on screen like a bug, so both fields go null together.
 */
it('refuses a browser identity for a retired agent — a number without a key is no phone', function () {
    $user = User::factory()->create();
    $extension = app(AgentPhoneWriter::class)->provisionFor($user);
    app(AgentPhoneWriter::class)->retireFor($user->fresh());

    expect($this->directory->browserIdentityFor($user->id))->toBe([
        'extension' => null,
        'password' => null,
        'wsUrl' => 'ws://127.0.0.1:8088/ws',
        'sipDomain' => 'asterisk.lab',
    ])->and($user->fresh()->sip_extension)->toBe($extension);
});
