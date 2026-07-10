<?php

namespace App\Filament\Auth;

use Filament\Auth\Pages\Login as BaseLogin;

/**
 * Branded login page. Only the visual shell changes: the view wraps the stock
 * $this->content schema (credentials form + 2FA challenge), so rate limiting,
 * remember-me and the TOTP flow keep Filament's untouched behavior.
 */
class Login extends BaseLogin
{
    protected string $view = 'filament.auth.login';

    protected static string $layout = 'filament.auth.login-layout';
}
