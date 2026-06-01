<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Tenancy\TenantSwitcher;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Web entry points for the M4.A client-context plumbing that live OUTSIDE the
 * Filament panel on purpose:
 *
 *  - suspended()     — the block screen a client-side user lands on when every
 *                      client they belong to is suspended/archived. It must not
 *                      sit behind SetCurrentTenant (that middleware is what
 *                      redirects here), or it would loop.
 *  - logout()        — a panel-independent logout for the block screen, since
 *                      Filament's own logout route runs through SetCurrentTenant
 *                      and would bounce a blocked user straight back.
 *  - switchTenant()  — records the chosen client for a multi-client user; the
 *                      middleware re-validates and re-pins on the next request.
 */
class TenantContextController extends Controller
{
    /**
     * The "your client is suspended" block screen (M3 §5.1, D-M4-6).
     */
    public function suspended(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('tenant.suspended', [
            'user' => $user,
            'tenants' => $user->tenants()->orderBy('tenants.name')->get(),
        ]);
    }

    /**
     * Panel-independent logout for the block screen.
     */
    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('filament.admin.auth.login');
    }

    /**
     * Switch the current client for a multi-client user (FR-U03). Default-deny:
     * TenantSwitcher::switchTo only records the choice after a membership check.
     */
    public function switchTenant(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'tenant' => ['required', 'integer'],
        ]);

        /** @var User $user */
        $user = $request->user();

        TenantSwitcher::switchTo($user, (int) $validated['tenant']);

        return redirect()->back();
    }
}
