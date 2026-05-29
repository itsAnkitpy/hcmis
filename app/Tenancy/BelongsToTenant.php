<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applied to every tenant-owned model. Wires up:
 *  - the mandatory TenantScope (auto-hide other tenants / default-deny),
 *  - auto-stamping of tenant_id on create from the active context,
 *  - a guard against writing one tenant's row while scoped to another,
 *  - tenant_id immutability on update (no moving a row between tenants).
 *
 * Migrations for these models must add a NOT NULL tenant_id FK to `tenants`.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $contextId = TenantContext::id();
            $explicit = $model->getAttribute('tenant_id');

            if ($explicit === null) {
                if ($contextId === null) {
                    throw TenantContextMissingException::forCreate($model::class);
                }

                $model->setAttribute('tenant_id', $contextId);

                return;
            }

            // tenant_id was set explicitly on the model.
            if (TenantContext::isCrossTenant()) {
                return; // audited path may write any tenant's row.
            }

            if ($contextId === null) {
                throw TenantContextMissingException::forCreate($model::class);
            }

            if ((int) $explicit !== $contextId) {
                throw TenantContextMissingException::crossTenantWrite($model::class, (int) $explicit, $contextId);
            }
        });

        static::updating(function (Model $model): void {
            // tenant_id is immutable once set — a row must never be moved
            // between tenants through the app layer. The audited cross path is
            // the only exception (e.g. data-correction tooling).
            if (! $model->isDirty('tenant_id')) {
                return;
            }

            if (TenantContext::isCrossTenant()) {
                return;
            }

            throw TenantContextMissingException::crossTenantWrite(
                $model::class,
                (int) $model->getAttribute('tenant_id'),
                (int) $model->getOriginal('tenant_id'),
            );
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
