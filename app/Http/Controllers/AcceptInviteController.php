<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Accept-invite endpoint (M3 Checkpoint C).
 *
 * The route is signed → middleware('signed') validates the URL hasn't been
 * tampered with and isn't expired. Both show + store re-confirm the email
 * matches the user record (defence in depth).
 *
 * On success: sets password, stamps email_verified_at, logs the user in,
 * redirects to the Filament panel.
 */
class AcceptInviteController extends Controller
{
    public function show(Request $request, User $user): View|RedirectResponse
    {
        if (! $this->emailsMatch($request, $user)) {
            abort(403, 'Invite link is no longer valid.');
        }

        if ($user->email_verified_at !== null) {
            return redirect()->route('filament.admin.auth.login')
                ->with('status', 'You have already accepted this invite. Please log in.');
        }

        return view('auth.accept-invite', [
            'user' => $user,
            'fullUrl' => $request->fullUrl(),
        ]);
    }

    public function store(Request $request, User $user): RedirectResponse
    {
        if (! $this->emailsMatch($request, $user)) {
            abort(403, 'Invite link is no longer valid.');
        }

        if ($user->email_verified_at !== null) {
            return redirect()->route('filament.admin.auth.login')
                ->with('status', 'You have already accepted this invite.');
        }

        $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user->forceFill([
            'password' => Hash::make($request->string('password')),
            'email_verified_at' => now(),
        ])->save();

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended('/admin');
    }

    private function emailsMatch(Request $request, User $user): bool
    {
        return $request->query('email') === $user->email;
    }
}
