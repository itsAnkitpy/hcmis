<?php

declare(strict_types=1);

namespace App\Telephony;

use App\Models\User;

/**
 * The agent -> phone directory (B2.2b RD-2 + S49 Fold A): the "this staff member
 * rings on this phone, and registers their browser as this identity" lookup.
 *
 * SEC-1 slice 4 (PP-11) moved the SOURCE from config/telephony.php's settings-list
 * to `users.sip_extension`, exactly as this class's original comment predicted. Its
 * callers did not change:
 *
 *   - the listener resolves a CHOSEN agent -> dial endpoint (endpointFor), to ring
 *     the free agent it reserved (CallToAgentFlow::beginCall);
 *   - each agent's own browser resolves the LOGGED-IN agent -> register identity
 *     (browserIdentityFor), so every agent registers as their own phone.
 *
 * 🔴 NEITHER method falls back any more (PP-12, the original bug). The config map
 * handed an unmapped user the single shared extension AND the shared password, so a
 * third agent silently became agent 1003 — their browser registered as 1003 and their
 * calls rang 1003. Both now return null for someone with no phone, which is a refusal
 * the agent can see rather than someone else's identity they cannot.
 *
 * Only `extension` and `password` come from the database. `wsUrl` and `sipDomain`
 * stay in config: one Asterisk for everyone.
 */
class AgentDirectory
{
    public function __construct(private readonly AgentPhoneWriter $phones) {}

    /**
     * The endpoint the listener dials to ring this agent (RD-2), or null when they
     * hold no extension — nobody else's phone, ever (PP-12).
     *
     * 🔴 A null must never reach `placeCall()`, which takes a non-nullable string:
     * a phoneless agent is filtered out of the who's-free board read before anyone
     * reserves them (AgentRouter), so the caller waits like they do when nobody is
     * free rather than the listener dying mid-call.
     */
    public function endpointFor(int $userId): ?string
    {
        $extension = User::query()->whereKey($userId)->value('sip_extension');

        // 'PJSIP/' is the one tech qualifier an agent phone has ever used, here and
        // in the hand-written pjsip.conf era. A config key for it would be a knob
        // that never turns.
        return $extension === null ? null : 'PJSIP/'.$extension;
    }

    /**
     * The browser-registration identity for the LOGGED-IN agent (Fold A): the
     * extension + key their own softphone registers with, plus the shared ws_url +
     * sip_domain.
     *
     * 🔴 A NUMBER WITHOUT A KEY IS A REFUSAL TOO. A retired agent (PP-19) keeps their
     * extension on their own row forever but their auth row is deleted, so they would
     * otherwise be handed a real extension and a null password — a registration that
     * fails at the switch and reads on screen like a bug. Both fields go null together
     * so the console has one thing to check.
     *
     * @return array{extension: ?string, password: ?string, wsUrl: ?string, sipDomain: string}
     */
    public function browserIdentityFor(int $userId): array
    {
        $user = User::query()->find($userId);
        $key = $user === null ? null : $this->phones->keyFor($user);

        return [
            'extension' => $key === null ? null : $user->sip_extension,
            'password' => $key,
            'wsUrl' => config('telephony.agent.ws_url'),
            'sipDomain' => config('telephony.agent.sip_domain'),
        ];
    }
}
