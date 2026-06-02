<?php

use App\Http\Middleware\SetCurrentTenant;
use Filament\Http\Middleware\Authenticate;
use Livewire\Livewire;

/**
 * Regression guard for the M4.D fix: the panel must register its auth + tenant
 * middleware as PERSISTENT, so they re-run on Livewire AJAX requests
 * (livewire/update), not only the initial full-page load. Without this the
 * active client is lost on every in-page interaction — RLS default-denies and
 * the create-gate 403s (the "Add custom field -> 403" symptom).
 *
 * Livewire::test() can't catch this (it bypasses HTTP middleware), so we assert
 * the persistent-middleware registry directly.
 */
it('keeps the tenant context middleware persistent across Livewire requests', function () {
    expect(Livewire::getPersistentMiddleware())
        ->toContain(SetCurrentTenant::class)
        ->toContain(Authenticate::class);
});
