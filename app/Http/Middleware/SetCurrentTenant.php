<?php

namespace App\Http\Middleware;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The login → current-client → walls bridge (M2). For an authenticated panel
 * request it resolves the user's current client and sets the M1 tenant context
 * — but ONLY after confirming the user is actually a member of that client.
 *
 * This membership check is the R-06 seam: it is the one place tenant context is
 * derived from the request, and it is validated server-side, default-deny. A
 * tampered session value silently falls back to a client the user may access,
 * never grants one they may not.
 *
 * Spatie's team id follows TenantContext automatically (see TenantTeamResolver),
 * so role checks scope to the same client with no extra call here.
 */
class SetCurrentTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            // Global HC roles (super_admin etc.) operate across clients at the
            // global team — never pin them to one client's context, which would
            // hide their global-team roles (Shield super-admin gate included).
            if ($user->operatesGlobally()) {
                TenantContext::forget();
                $request->session()->forget('current_tenant_id');

                return $next($request);
            }

            $tenantId = $this->resolveTenantId($user, $request);

            if ($tenantId !== null) {
                TenantContext::set($tenantId);
                $request->session()->put('current_tenant_id', $tenantId);
            } else {
                TenantContext::forget();
                $request->session()->forget('current_tenant_id');
            }
        }

        return $next($request);
    }

    /**
     * Reset the context after the response is sent. Belt-and-suspenders for
     * persistent-worker runtimes (e.g. Octane) where statics survive requests.
     */
    public function terminate(Request $request, Response $response): void
    {
        TenantContext::forget();
    }

    /**
     * The session choice if the user may access it AND it is operable
     * (M3 §5.1 — suspended / archived tenants never get bound into context);
     * otherwise their first operable client; otherwise null.
     */
    private function resolveTenantId(User $user, Request $request): ?int
    {
        $sessionId = $request->session()->get('current_tenant_id');

        if ($sessionId !== null && $user->mayAccessTenant((int) $sessionId)) {
            $tenant = Tenant::find((int) $sessionId);

            if ($tenant?->isOperable()) {
                return (int) $sessionId;
            }
        }

        return $user->tenants()
            ->where('status', TenantStatus::Active->value)
            ->orderBy('tenants.id')
            ->value('tenants.id');
    }
}
