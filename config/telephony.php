<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Telephony provider
    |--------------------------------------------------------------------------
    |
    | Which TelephonyProvider implementation the container binds (B1 D3).
    | One real provider today — 'asterisk' (Asterisk 20 over ARI, D-001).
    | A future vendor swap is this one line plus a new implementation; no
    | Manager/driver machinery until a second provider actually exists.
    |
    */

    'provider' => env('TELEPHONY_PROVIDER', 'asterisk'),

    /*
    |--------------------------------------------------------------------------
    | Asterisk ARI connection
    |--------------------------------------------------------------------------
    |
    | ARI is two pipes into the same host/port: plain HTTP for commands and
    | one long-lived WebSocket for events. 'app' is the Stasis() application
    | name — calls only reach us while a listener holding that name is
    | connected. 'context' is the dialplan context transfers continue into.
    |
    */

    'asterisk' => [
        'host' => env('TELEPHONY_ARI_HOST', '127.0.0.1'),
        'port' => (int) env('TELEPHONY_ARI_PORT', 8088),
        'username' => env('TELEPHONY_ARI_USERNAME'),
        'password' => env('TELEPHONY_ARI_PASSWORD'),
        'app' => env('TELEPHONY_ARI_APP', 'hcmis-lab'),
        'context' => env('TELEPHONY_ARI_CONTEXT', 'internal'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Agent phone (B4 D7 — config map, lab-only)
    |--------------------------------------------------------------------------
    |
    | The Agent Console registers itself as a SIP-over-WebSocket phone (the A4
    | path). v1 has one agent, so a config map gets the demo real with zero
    | schema (per-agent provisioning is a trunk-era module). The browser reads
    | these to register; the listener dials 'endpoint' to ring this agent's leg
    | (verified against the lab at the CP2a checkpoint). Everything is env-driven
    | and the lab SIP password is a throwaway, never committed.
    |
    | PROD NOTE: production runs browser <-> Asterisk over 'wss://' + a real
    | cert (infra §2.9), and must not hand a real SIP secret to the browser this
    | way — the lab's plain 'ws://127.0.0.1' shortcut never ships (D7 reopen).
    |
    */

    'agent' => [
        'extension' => env('TELEPHONY_AGENT_EXTENSION'),
        'endpoint' => env('TELEPHONY_AGENT_ENDPOINT', 'PJSIP/'.env('TELEPHONY_AGENT_EXTENSION')),
        'password' => env('TELEPHONY_AGENT_PASSWORD'),
        'ws_url' => env('TELEPHONY_AGENT_WS_URL'),
        'sip_domain' => env('TELEPHONY_AGENT_SIP_DOMAIN', 'asterisk.lab'),

        /*
        | B2.2b (RD-2 + S49 Fold A): the per-agent phone directory — the settings-list
        | that lets the listener ring a CHOSEN agent AND lets each agent's own browser
        | register as THEIR phone, not the one shared extension above. Keyed by user id;
        | lab rows only (real per-agent provisioning is the trunk-era table that replaces
        | this — only the resolver's source changes, its callers don't). The shared
        | ws_url + sip_domain stay above (same Asterisk for everyone). Passwords env-only.
        |
        | Read by App\Telephony\AgentDirectory: endpointFor() (listener dials a chosen
        | agent) and browserIdentityFor() (a logged-in agent's browser registers as self).
        */
        'directory' => [
            // user 6 (Abhikesh, abc@gmail.com) keeps the existing single-agent env,
            // so the established 1003 lab identity needs no .env churn.
            6 => [
                'extension' => env('TELEPHONY_AGENT_EXTENSION', '1003'),
                'endpoint' => env('TELEPHONY_AGENT_ENDPOINT', 'PJSIP/1003'),
                'password' => env('TELEPHONY_AGENT_PASSWORD'),
            ],
            // user 7 (Demo Agent Two, def@gmail.com) — the 2nd distinct lab phone (RD-6).
            7 => [
                'extension' => env('TELEPHONY_AGENT2_EXTENSION', '1004'),
                'endpoint' => env('TELEPHONY_AGENT2_ENDPOINT', 'PJSIP/1004'),
                'password' => env('TELEPHONY_AGENT2_PASSWORD'),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Outbound dialing (B-outbound O2 + plumbing)
    |--------------------------------------------------------------------------
    |
    | 'caller_id' is the single configured "from" number the customer sees on an
    | outbound call (O2 — one value in v1; per-campaign DIDs ride the trunk).
    |
    | 'dial_prefix' is the tech-qualified endpoint prefix the flow prepends to a
    | bare customer number to reach it (the bare number rides as a clean app-arg;
    | the engine-specific "how to reach it" lives here, not in the flow). Lab dials
    | softphones over PJSIP; the trunk era overrides this via env.
    |
    */

    'outbound' => [
        'caller_id' => env('TELEPHONY_OUTBOUND_CALLER_ID'),
        'dial_prefix' => env('TELEPHONY_OUTBOUND_DIAL_PREFIX', 'PJSIP/'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Recordings
    |--------------------------------------------------------------------------
    |
    | Where merged stereo MP3s land (PW5-4: caller left, agent right). The
    | raw WAVs are pulled off the voice box over HTTP and merged by a queued
    | job — the voice box never pays for MP3 encoding mid-call.
    |
    */

    'recordings' => [
        'disk' => env('TELEPHONY_RECORDINGS_DISK', 'local'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Presence — the who's-free board (B2.2 PD-4)
    |--------------------------------------------------------------------------
    |
    | The agent's screen stamps "still here" every 'heartbeat_seconds'. A board
    | row whose last stamp is older than 'stale_after_seconds' reads as Offline
    | (a crashed tab must not leave a lying "Ready"). The default ~15s/~60s is the
    | common heartbeat/timeout pattern (PD-4 internet check). The browser timer
    | reads heartbeat_seconds; the server staleness check reads stale_after.
    |
    */

    'presence' => [
        'heartbeat_seconds' => (int) env('TELEPHONY_PRESENCE_HEARTBEAT', 15),
        'stale_after_seconds' => (int) env('TELEPHONY_PRESENCE_STALE_AFTER', 60),
    ],

];
