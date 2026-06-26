<?php

use App\Telephony\AgentDirectory;

/**
 * B2.2b RD-2 + S49 Fold A — the agent->phone directory. One settings-list, two
 * readers: the listener resolves a CHOSEN agent -> dial endpoint (endpointFor); each
 * agent's browser resolves the LOGGED-IN agent -> register identity (browserIdentityFor).
 * Both fall back to the single-agent config for any user not in the directory, so the
 * legacy one-agent lab path stays unbroken.
 */
beforeEach(function () {
    config()->set('telephony.agent.extension', '1003');
    config()->set('telephony.agent.password', 'legacy-secret');
    config()->set('telephony.agent.endpoint', 'PJSIP/1003');
    config()->set('telephony.agent.ws_url', 'ws://127.0.0.1:8088/ws');
    config()->set('telephony.agent.sip_domain', 'asterisk.lab');
    config()->set('telephony.agent.directory', [
        7 => ['extension' => '1004', 'endpoint' => 'PJSIP/1004', 'password' => 'agent2-secret'],
    ]);
});

it('resolves a mapped agent to their dial endpoint (RD-2)', function () {
    expect((new AgentDirectory)->endpointFor(7))->toBe('PJSIP/1004');
});

it('falls back to the single-agent endpoint for an unmapped agent', function () {
    expect((new AgentDirectory)->endpointFor(999))->toBe('PJSIP/1003');
});

it('resolves a mapped agent\'s browser identity — their own extension + password (Fold A)', function () {
    expect((new AgentDirectory)->browserIdentityFor(7))->toBe([
        'extension' => '1004',
        'password' => 'agent2-secret',
        'wsUrl' => 'ws://127.0.0.1:8088/ws',
        'sipDomain' => 'asterisk.lab',
    ]);
});

it('falls back to the single-agent identity for an unmapped agent (the one-agent lab path)', function () {
    expect((new AgentDirectory)->browserIdentityFor(999))->toBe([
        'extension' => '1003',
        'password' => 'legacy-secret',
        'wsUrl' => 'ws://127.0.0.1:8088/ws',
        'sipDomain' => 'asterisk.lab',
    ]);
});
