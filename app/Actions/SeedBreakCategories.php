<?php

namespace App\Actions;

use App\Models\BreakCategory;
use App\Models\Tenant;
use App\Tenancy\TenantContext;

/**
 * Copy the starter break types from config/hcims.php break_category_defaults
 * into real `break_categories` rows for one tenant (BK-1).
 *
 * Called from TenantObserver::created, so every code path that produces a
 * tenant — wizard, factory, seeders, tinker — ends up with the same six
 * Dialshree types. Mirrors SeedCampaignDispositions, with one difference:
 * break types are tenant-wide, so the hook is tenant creation, not campaign
 * creation.
 *
 * Behaviour:
 *  - runs inside TenantContext::run() for the target tenant, because the
 *    observer fires from context-less paths (the wizard creates the Tenant
 *    row before entering context) and RLS + BelongsToTenant both need it;
 *  - seed-once: a tenant that already has ≥1 category is left untouched, so
 *    editing the config later never retro-changes existing tenants and the
 *    action is safe to re-run (the existing-tenant backfill uses exactly that);
 *  - no time limits seeded — real per-type times are an open ops question;
 *    admins enter them on the Break Categories screen;
 *  - seeds write NO audit rows: this is platform provisioning, not an admin
 *    action (same posture as ProvisionTenantRoles). Manual creates and every
 *    later edit through the panel stay fully audited (BK-1).
 */
class SeedBreakCategories
{
    public function __invoke(Tenant $tenant): void
    {
        TenantContext::run($tenant->getKey(), function (): void {
            if (BreakCategory::query()->exists()) {
                return;
            }

            /** @var array<int, array{code: string, label: string}> $rows */
            $rows = config('hcims.break_category_defaults', []);

            foreach ($rows as $index => $row) {
                $category = new BreakCategory([
                    'code' => $row['code'],
                    'label' => $row['label'],
                    'sort_order' => $index,
                ]);

                $category->disableLogging();
                $category->save();
            }
        });
    }

    /**
     * Convenience callable for use as an action target.
     */
    public static function run(Tenant $tenant): void
    {
        (new self)($tenant);
    }
}
