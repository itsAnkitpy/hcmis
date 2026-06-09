<?php

namespace App\Listeners;

use App\Audit\Audit;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;

/**
 * The auth half of the audit trail (M7 D-M7-2): login, logout, failed login and
 * password reset. These are not model changes, so they are logged here rather
 * than via LogsModelActivity. Subscribed in AppServiceProvider::boot().
 *
 * A failed login records only the attempted email — never the submitted
 * password (the credentials array carries it, so it must not be logged whole).
 */
class LogAuthenticationActivity
{
    public function handleLogin(Login $event): void
    {
        Audit::authEvent('login', $event->user instanceof User ? $event->user : null);
    }

    public function handleLogout(Logout $event): void
    {
        Audit::authEvent('logout', $event->user instanceof User ? $event->user : null);
    }

    public function handleFailed(Failed $event): void
    {
        $email = $event->credentials['email'] ?? null;

        Audit::authEvent('failed_login', null, $email !== null ? ['email' => $email] : []);
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        Audit::authEvent('password_reset', $event->user instanceof User ? $event->user : null);
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'handleLogin',
            Logout::class => 'handleLogout',
            Failed::class => 'handleFailed',
            PasswordReset::class => 'handlePasswordReset',
        ];
    }
}
