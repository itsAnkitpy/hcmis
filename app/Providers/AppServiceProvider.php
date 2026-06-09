<?php

namespace App\Providers;

use App\Listeners\LogAuthenticationActivity;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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
