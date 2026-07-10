<?php

namespace App\Providers\Filament;

use App\Filament\Auth\Login;
use App\Filament\Pages\Dashboard;
use App\Http\Middleware\SetCurrentTenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantSwitcher;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->brandName('HighlandConnect')
            ->login(Login::class)
            ->profile()
            ->sidebarCollapsibleOnDesktop()
            // M5 — the queued lead import notifies the uploader on completion via
            // a database notification (D-M5-10); the bell surfaces it after the
            // async job finishes, since the user may have navigated away.
            ->databaseNotifications()
            ->multiFactorAuthentication([
                AppAuthentication::make()->recoverable(), // optional TOTP 2FA (FR-U04); users opt in via profile
            ])
            ->colors([
                'primary' => Color::Teal,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->plugins([
                FilamentShieldPlugin::make(),
            ])
            // M4.A — topbar client context / switcher (D-M4-6). Logic stays in
            // the closure; the view is pure presentation.
            ->renderHook(
                PanelsRenderHook::USER_MENU_BEFORE,
                function (): string {
                    $user = auth()->user();

                    if (! $user instanceof User) {
                        return '';
                    }

                    return view('filament.client-switcher', [
                        'tenants' => TenantSwitcher::allowedTenants($user),
                        'currentId' => TenantContext::id(),
                        'isGlobal' => $user->operatesGlobally(),
                    ])->render();
                },
            )
            // Fail-closed authorization: a resource page with no matching model
            // policy method throws instead of silently allowing access. Prevents
            // the M3 gap (Tenant/User resources were visible to everyone for lack
            // of a policy) from recurring as new resources land in M4+.
            ->strictAuthorization()
            // isPersistent so the auth + tenant-context middleware re-run on
            // Livewire AJAX requests (livewire/update), not just the initial page
            // load. Without this the active client is lost on every in-page
            // interaction (add repeater row, save, table action) — RLS then
            // default-denies and the create-gate 403s. The catastrophic seam must
            // hold on Livewire requests too, not only full page loads.
            ->authMiddleware([
                Authenticate::class,
                SetCurrentTenant::class,
            ], isPersistent: true);
    }
}
