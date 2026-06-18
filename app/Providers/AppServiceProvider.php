<?php

namespace App\Providers;

use App\Enums\RoleName;
use App\Events\Telephony\RecordingReady;
use App\Listeners\AttachRecordingToCall;
use App\Listeners\LogAuthenticationActivity;
use App\Models\User;
use App\Telephony\AsteriskAriProvider;
use App\Telephony\TelephonyProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
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

        // B3 CP-B3-2 (D3/D6): attach the merged recording to its calls row by UUID,
        // off the queue (ShouldQueue) with bounded retry for the merge-vs-wrap-up race.
        Event::listen(RecordingReady::class, AttachRecordingToCall::class);

        // B4 CP3 (decision C): the narrow write-gate for recording a handled
        // call's outcome. Same population as AgentConsole::canAccess() — agent +
        // global staff — but a DISTINCT named ability, deliberately not
        // LeadPolicy::update: agents still have no Leads CRUD (D-M4-5). super_admin
        // also passes via Shield's Gate::before; operatesGlobally covers the rest.
        Gate::define(
            'record-call-outcome',
            fn (User $user): bool => $user->operatesGlobally() || $user->hasRole(RoleName::Agent->value),
        );

        // CP-O3 (D3): the ad-hoc dial gate — an agent typing a one-off number
        // instead of dialing a served lead. Same population as record-call-outcome
        // for v1 (agent + global staff); selective per-agent grant is deferred TL
        // tooling (no lead-ownership / assignment layer exists yet). super_admin
        // passes via Shield's Gate::before.
        Gate::define(
            'dial-adhoc',
            fn (User $user): bool => $user->operatesGlobally() || $user->hasRole(RoleName::Agent->value),
        );
    }
}
