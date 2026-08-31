<?php

namespace App\Providers;

use App\Enums\RoleName;
use App\Listeners\LogAuthenticationActivity;
use App\Models\User;
use App\Telephony\AsteriskAriProvider;
use App\Telephony\TelephonyProvider;
use App\Tenancy\TenantContext;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * The panel's reading zone per client, for the lifetime of this request only.
     *
     * 🔴 IT IS CACHED BECAUSE FILAMENT ASKS ONCE PER CELL. Reading the client record
     * inside the closure below would be one database query for every date on every row
     * of every table — the unbounded-per-render shape the export screen already has and
     * that nothing should copy. The provider is built fresh per request, and the key is
     * the client id, so switching client in the topbar cannot serve the wrong zone.
     *
     * @var array<int|string, string>
     */
    private array $panelTimezones = [];

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
        // 🔴 S118 (CE-10a). Every date-AND-time value the panel prints is shown on the
        // CLIENT'S clock. The database keeps UTC and nothing about storage changes; this
        // is the reading end only. A closure, not a string, because the answer depends on
        // which client the request is bound to, and that is not known at boot.
        //
        // Date-ONLY fields are deliberately untouched: Filament reads this setting only
        // where a field carries a time, so the report filter pickers still hand over a
        // plain date, which each report then cuts in the client's zone itself.
        //
        // The one WRITE this moves is the DNC entry expiry (the panel's only
        // date-and-time picker): a typed expiry now means the client's clock. That is
        // the correction, not a side effect.
        FilamentTimezone::set(fn (): string => $this->panelTimezone());

        // Audit the auth events (M7 D-M7-2): login / logout / failed / reset.
        Event::subscribe(LogAuthenticationActivity::class);

        // B3 CP-B3-2's recording attach (RecordingReady -> AttachRecordingToCall) is NOT
        // registered here. Laravel finds it by scanning app/Listeners, and registering it
        // by hand as well meant it ran TWICE on every recorded call — visible on staging
        // as every orphan warning logged in duplicate at an identical timestamp
        // (2026-08-11, 2026-08-12). Harmless in effect, since both runs write the same
        // values, but double the queue work and twice as confusing to read a log through.
        // `php artisan event:list` is the check if this is ever in doubt.

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

        // CP-3: writing the customer record from the live-call form. A DISTINCT
        // named ability rather than LeadPolicy::create/update, for the same reason
        // record-call-outcome is one: agents hold no Leads CRUD (D-M4-5) and this
        // must not hand it to them by the side door. Same population as the other
        // two console write-gates; super_admin passes via Shield's Gate::before.
        Gate::define(
            'save-customer',
            fn (User $user): bool => $user->operatesGlobally() || $user->hasRole(RoleName::Agent->value),
        );
    }

    /**
     * The active client's reading zone, read once per client per request.
     *
     * Global staff hold no single client to ask, so they key on 'global' and get the
     * system default — the same answer the Call Export gives them.
     */
    private function panelTimezone(): string
    {
        return $this->panelTimezones[TenantContext::id() ?? 'global'] ??= TenantContext::reportTimezone();
    }
}
