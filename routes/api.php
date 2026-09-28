<?php

use App\Http\Controllers\VoiceMissedCallController;
use Illuminate\Support\Facades\Route;

// AIV-1 AB-4 — the hcmis-voice program reports a finished AI call. No login: the shared
// secret in StoreVoiceMissedCallRequest is the guard, and the client comes from the
// dialled number. The `api` group carries no session and no CSRF check (Laravel 13.12,
// Configuration/Middleware.php), which is why this is not in web.php.
Route::post('/voice/missed-calls', VoiceMissedCallController::class)->name('voice.missed-calls.store')
    // A leaked secret or a retry loop must not flood Missed Calls. One sender, so per IP is fine.
    ->middleware('throttle:60,1');
