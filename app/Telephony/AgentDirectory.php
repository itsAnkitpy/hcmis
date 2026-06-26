<?php

declare(strict_types=1);

namespace App\Telephony;

/**
 * The agent -> phone directory (B2.2b RD-2 + S49 Fold A): the "this staff member
 * rings on this phone, and registers their browser as this identity" lookup.
 *
 * v1 is a settings-list (config/telephony.php `agent.directory`), keyed by user id —
 * the genuinely new B2.2b work is the routing (read board -> pick -> reserve -> ring),
 * not where a phone string is filed, so a real table for two lab rows would build
 * ahead of need. Real per-agent provisioning (trunk-era) moves this into a
 * tenant-scoped table; only this resolver's SOURCE changes, its two callers do not:
 *
 *   - the listener resolves a CHOSEN agent -> dial endpoint (endpointFor), to ring
 *     the free agent it reserved (CallToAgentFlow::beginCall);
 *   - each agent's own browser resolves the LOGGED-IN agent -> register identity
 *     (browserIdentityFor), so a 2nd agent registers as a 2nd phone — not the one
 *     shared extension getPhoneConfig handed everyone before Fold A.
 *
 * Both fall back to the single-agent config for any user not in the directory, so
 * the legacy one-agent lab path (B2.2a and earlier) stays unbroken.
 */
class AgentDirectory
{
    /**
     * The endpoint the listener dials to ring this agent (RD-2). Falls back to the
     * single-agent `agent.endpoint` when the user is not in the directory.
     */
    public function endpointFor(int $userId): ?string
    {
        $directory = config('telephony.agent.directory', []);

        return $directory[$userId]['endpoint'] ?? config('telephony.agent.endpoint');
    }

    /**
     * The browser-registration identity for the LOGGED-IN agent (Fold A): the
     * extension + password their own softphone registers with, plus the shared
     * ws_url + sip_domain (one Asterisk for everyone). Falls back to the
     * single-agent config for any user not in the directory.
     *
     * @return array{extension: ?string, password: ?string, wsUrl: ?string, sipDomain: string}
     */
    public function browserIdentityFor(int $userId): array
    {
        $row = config('telephony.agent.directory', [])[$userId] ?? [];

        return [
            'extension' => $row['extension'] ?? config('telephony.agent.extension'),
            'password' => $row['password'] ?? config('telephony.agent.password'),
            'wsUrl' => config('telephony.agent.ws_url'),
            'sipDomain' => config('telephony.agent.sip_domain'),
        ];
    }
}
