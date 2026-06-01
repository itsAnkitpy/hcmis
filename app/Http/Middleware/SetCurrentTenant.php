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
            // At the DB they take the audited cross-tenant posture (bypass on)
            // so RLS lets them read every client's operational data (M4+).
            if ($user->operatesGlobally()) {
                TenantContext::applyWebRequest(null, crossTenant: true);
                $request->session()->forget('current_tenant_id');

                return $next($request);
            }

            $tenantId = $this->resolveTenantId($user, $request);

            if ($tenantId !== null) {
                // Client-side staff pinned to one operable client. Stamp the
                // Postgres marker (plain SET) so RLS filters M4+ tables for the
                // whole request, not just queued jobs.
                TenantContext::applyWebRequest($tenantId, crossTenant: false);
                $request->session()->put('current_tenant_id', $tenantId);

                // canAccessPanel() ran in the Authenticate middleware BEFORE this
                // one and loaded the user's spatie roles scoped to the GLOBAL team
                // (no client set yet), caching them on the model. Now the client
                // context is set — drop those cached relations so per-client role
                // checks (policies) re-resolve at the active team. Without this a
                // team_leader / QC / trainer would be judged against their empty
                // global-team roles and denied their own client's data.
                $user->unsetRelation('roles')->unsetRelation('permissions');

                return $next($request);
            }

            // Reaching here, the user is client-side staff (not global) who has
            // membership(s) — else canAccessPanel() would have denied them — but
            // none is operable: their client is suspended/archived. Wipe the
            // marker (DB default-deny) and show the block screen instead of a
            // context-less panel (M3 §5.1, D-M4-6). Protects team_leader / QC /
            // trainer in M4.
            TenantContext::applyWebRequest(null, crossTenant: false);
            $request->session()->forget('current_tenant_id');

            return redirect()->route('tenant.suspended');
        }

        return $next($request);
    }

    /**
     * Reset the marker after the response is sent. The primary leak guard is
     * set-fresh-at-start in handle(); this is belt-and-suspenders for
     * persistent-worker runtimes (e.g. Octane) where statics + connections
     * survive between requests.
     */
    public function terminate(Request $request, Response $response): void
    {
        TenantContext::resetWebRequest();
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
