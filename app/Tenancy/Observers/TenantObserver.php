<?php

namespace App\Tenancy\Observers;

use App\Models\Tenant;
use App\Tenancy\Actions\ProvisionTenantRoles;

/**
 * Auto-provisions per-tenant roles whenever a tenant is created. Wired via
 * the #[ObservedBy(...)] attribute on the Tenant model so the side effect
 * applies to every creation path — wizard, factory, API, seeders, tinker.
 */
class TenantObserver
{
    public function created(Tenant $tenant): void
    {
        ProvisionTenantRoles::run($tenant);
    }
}
