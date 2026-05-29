<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Mandatory global scope for tenant-owned models (D-002 layer 2).
 *
 * - Inside an audited cross-tenant block: no constraint (sees all tenants).
 * - With a tenant set: constrains every query to that tenant.
 * - With no tenant set: throws (default-deny — never returns all rows).
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (TenantContext::isCrossTenant()) {
            return;
        }

        if (! TenantContext::has()) {
            throw TenantContextMissingException::forQuery($model::class);
        }

        $builder->where($model->qualifyColumn('tenant_id'), TenantContext::id());
    }
}
