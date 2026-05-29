<?php

namespace App\Tenancy;

use RuntimeException;

/**
 * Thrown when tenant-owned data is touched without a tenant context — the
 * concrete expression of D-002's default-deny rule. A leak shows up as a
 * thrown exception in tests/CI, never as silently-returned cross-tenant rows.
 */
class TenantContextMissingException extends RuntimeException
{
    public static function forQuery(string $model): self
    {
        return new self(
            "No tenant context set while querying [{$model}]. Wrap the call in TenantContext::run(\$tenantId, fn) or, for a legitimate cross-tenant operation, TenantContext::cross(fn)."
        );
    }

    public static function forCreate(string $model): self
    {
        return new self(
            "Cannot create [{$model}] without a tenant context. Wrap the write in TenantContext::run(\$tenantId, fn), or pass an explicit tenant_id inside TenantContext::cross(fn)."
        );
    }

    public static function crossTenantWrite(string $model, int $attempted, int $current): self
    {
        return new self(
            "Blocked cross-tenant write on [{$model}]: tenant_id {$attempted} does not match the active tenant context {$current}."
        );
    }
}
