<?php

use App\Http\Controllers\AcceptInviteController;
use App\Http\Controllers\CallExportController;
use App\Http\Controllers\CallRecordingController;
use App\Http\Controllers\TenantContextController;
use App\Http\Middleware\SetCurrentTenant;
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

// Call Review (CR-2) — gated streaming of a private call recording. SetCurrentTenant
// puts the request in the viewer's tenant context so the {call} binding is RLS-scoped
// (a foreign client's call id is simply not found); CallPolicy + the on-disk check do
// the rest, inside the controller.
// Call Export (CE-5b) — the streamed one-row-per-call spreadsheet. Same group as the
// recording route for the same reason: SetCurrentTenant puts the whole write in the
// viewer's tenant context, and it survives to the last row (the binding is cleared in
// terminate(), after the response is sent). It is a plain route rather than a Filament
// action because Livewire buffers a download in memory instead of streaming it.
Route::middleware(['auth', SetCurrentTenant::class])->group(function () {
    Route::get('/calls/{record}/recording', CallRecordingController::class)->name('calls.recording');
    Route::get('/calls/export', CallExportController::class)->name('calls.export');
});

// M3 Checkpoint C — accept-invite (signed URL, public route).
Route::middleware(['web', 'signed'])->group(function () {
    Route::get('/invite/{user}/accept', [AcceptInviteController::class, 'show'])->name('invite.accept');
    Route::post('/invite/{user}/accept', [AcceptInviteController::class, 'store'])->name('invite.accept.store');
});
