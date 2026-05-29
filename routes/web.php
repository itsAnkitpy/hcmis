<?php

use App\Http\Controllers\AcceptInviteController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// M3 Checkpoint C — accept-invite (signed URL, public route).
Route::middleware(['web', 'signed'])->group(function () {
    Route::get('/invite/{user}/accept', [AcceptInviteController::class, 'show'])->name('invite.accept');
    Route::post('/invite/{user}/accept', [AcceptInviteController::class, 'store'])->name('invite.accept.store');
});
