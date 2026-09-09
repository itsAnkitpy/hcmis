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
    | Agent phone — the shared half (B4 D7, pruned by SEC-1 slice 5)
    |--------------------------------------------------------------------------
    |
    | The Agent Console registers itself as a SIP-over-WebSocket phone (the A4
    | path). WHICH phone is no longer answered here: an agent's extension lives
    | on their own row in `users.sip_extension` and their key in Asterisk's
    | `ps_auths`, written by App\Telephony\AgentPhoneWriter and read back by
    | App\Telephony\AgentDirectory. The per-user map that used to answer it,
    | and the single shared extension/endpoint/password it fell back to, went
    | with the last hand-written pjsip.conf phones (PP-14, PP-15).
    |
    | What stays is the half that is identical for everyone: one Asterisk, so
    | one ws_url and one sip_domain.
    |
    | PROD NOTE: production runs browser <-> Asterisk over 'wss://' + a real
    | cert (infra §2.9), and must not hand a real SIP secret to the browser this
    | way — the lab's plain 'ws://127.0.0.1' shortcut never ships (D7 reopen).
    |
    */

    'agent' => [
        'ws_url' => env('TELEPHONY_AGENT_WS_URL'),
        'sip_domain' => env('TELEPHONY_AGENT_SIP_DOMAIN', 'asterisk.lab'),
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
    | 'dial_suffix' is the other half of the same seam. A lab softphone IS an
    | endpoint ('PJSIP/1003'), but a real number is not — it has to be dialled
    | THROUGH one ('PJSIP/+919xxxxxxxxx@twilio'), and the trunk name lands after
    | the number. Empty by default, so the lab is unchanged; the server sets
    | TELEPHONY_OUTBOUND_DIAL_SUFFIX=@twilio.
    |
    */

    'outbound' => [
        'caller_id' => env('TELEPHONY_OUTBOUND_CALLER_ID'),
        'dial_prefix' => env('TELEPHONY_OUTBOUND_DIAL_PREFIX', 'PJSIP/'),
        'dial_suffix' => env('TELEPHONY_OUTBOUND_DIAL_SUFFIX', ''),
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
    | The agent's screen stamps "still here" every 15s. A board row whose last
    | stamp is older than 'stale_after_seconds' reads as Offline (a crashed tab
    | must not leave a lying "Ready"), and the SAME window decides who is eligible
    | for the next caller (AgentRouter::reserveFreeAgent).
    |
    | WHY 120 AND NOT 60 (S93). Chrome throttles a hidden tab's timers to ONCE PER
    | MINUTE once it has been in the background ~5 minutes, and — unlike Firefox —
    | holding a WebSocket open does NOT exempt it, so the console's SIP connection
    | buys nothing here. At a 60s window the throttled beat lands exactly on the
    | cutoff, so an agent sitting Ready with the console in a background tab aged
    | off the board AND silently stopped being offered calls. Confirmed live, not
    | theorised. 120s clears one throttled beat with margin.
    | Reference: https://developer.chrome.com/blog/timer-throttling-in-chrome-88
    |
    | THE TRADE, taken deliberately: a genuinely dead tab now holds a lying "Ready"
    | for up to 120s instead of 60s, so one caller may burn one ring on an empty
    | desk. Bounded — the per-call skip list (QD-4) moves them straight on to the
    | next agent. Losing calls from live agents is the worse failure.
    |
    | 'heartbeat_seconds' is NOT read by anything: the browser timer hardcodes 15s
    | at resources/js/agent-console.js. Kept because it documents the intended beat
    | and rides an env var that may already be set on a box. Wire it or drop it —
    | but do not trust it as live.
    |
    */

    'presence' => [
        'heartbeat_seconds' => (int) env('TELEPHONY_PRESENCE_HEARTBEAT', 15),
        'stale_after_seconds' => (int) env('TELEPHONY_PRESENCE_STALE_AFTER', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | The waiting room (B2.3b-i QD-7)
    |--------------------------------------------------------------------------
    |
    | 'ring_seconds' is how long ONE agent's phone rings before we give up on
    | them and put the caller back in the waiting room; 'max_hold_seconds' is how
    | long a caller may hold before we stop waiting, end the call, and write them
    | to the missed-call list.
    |
    | These are the FALLBACKS. A client that sets its own (tenants.ring_seconds /
    | tenants.max_hold_seconds) overrides them — per-client values must not live
    | in this file, which sits on the server and would need a deploy per client
    | (the ND-2 precedent).
    |
    | Both defaults are honest guesses, cheap to change: 20s is long enough to
    | reach a desk and short enough that a waiting caller is not parked on one
    | absent agent; 180s is three minutes, to be replaced with real numbers once
    | the line carries real traffic.
    |
    */

    'queue' => [
        'ring_seconds' => (int) env('TELEPHONY_RING_SECONDS', 20),
        'max_hold_seconds' => (int) env('TELEPHONY_MAX_HOLD_SECONDS', 180),
    ],

];
