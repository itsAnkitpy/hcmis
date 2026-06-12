<?php

namespace App\Providers;

use App\Listeners\LogAuthenticationActivity;
use App\Telephony\AsteriskAriProvider;
use App\Telephony\TelephonyProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // B1 D3: one telephony interface, one real implementation, a config
        // key naming it. No Manager/driver machinery until a second provider
        // actually exists.
        $this->app->singleton(TelephonyProvider::class, fn (): TelephonyProvider => match ($provider = config('telephony.provider')) {
            'asterisk' => new AsteriskAriProvider,
            default => throw new InvalidArgumentException("Unknown telephony provider [{$provider}]."),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Audit the auth events (M7 D-M7-2): login / logout / failed / reset.
        Event::subscribe(LogAuthenticationActivity::class);
    }
}
