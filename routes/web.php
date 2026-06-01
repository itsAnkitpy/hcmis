<?php

use App\Http\Controllers\AcceptInviteController;
use App\Http\Controllers\TenantContextController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// M4.A — client-context plumbing that lives outside the Filament panel
// (see TenantContextController for why each route is here, not in the panel).
Route::middleware('auth')->group(function () {
    Route::get('/client-suspended', [TenantContextController::class, 'suspended'])->name('tenant.suspended');
    Route::post('/client-suspended/logout', [TenantContextController::class, 'logout'])->name('tenant.logout');
    Route::post('/clients/switch', [TenantContextController::class, 'switchTenant'])->name('tenant.switch');
});

// M3 Checkpoint C — accept-invite (signed URL, public route).
Route::middleware(['web', 'signed'])->group(function () {
    Route::get('/invite/{user}/accept', [AcceptInviteController::class, 'show'])->name('invite.accept');
    Route::post('/invite/{user}/accept', [AcceptInviteController::class, 'store'])->name('invite.accept.store');
});
