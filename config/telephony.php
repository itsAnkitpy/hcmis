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

];
