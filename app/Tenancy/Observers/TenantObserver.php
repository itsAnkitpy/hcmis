<?php

namespace App\Tenancy\Observers;

use App\Actions\SeedBreakCategories;
use App\Models\Tenant;
use App\Tenancy\Actions\ProvisionTenantRoles;

/**
 * Auto-provisions per-tenant roles and seeds the starter break types whenever
 * a tenant is created. Wired via the #[ObservedBy(...)] attribute on the
 * Tenant model so the side effects apply to every creation path — wizard,
 * factory, API, seeders, tinker.
 */
class TenantObserver
{
    public function created(Tenant $tenant): void
    {
        ProvisionTenantRoles::run($tenant);
        SeedBreakCategories::run($tenant);
    }
}
