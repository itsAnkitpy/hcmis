<?php

namespace App\Models;

use App\Tenancy\TenantContext;
use App\Tenancy\TenantScope;
use Database\Factories\ActivityLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Activity;

/**
 * The audit log row (M7). Extends Spatie's Activity so one event stream holds
 * both tenant-owned events ("client A's lead was edited") and ownerless/global
 * events (logins, super-admin platform actions, the no-auth import job) under
 * the project's tenancy model (D-M7-1).
 *
 * Read isolation REUSES the standard TenantScope: a pinned client sees only its
 * own rows (ownerless rows excluded), the audited cross-tenant path (HC staff)
 * sees everything including ownerless rows — the same wall the variant RLS
 * policy enforces at the DB. It deliberately does NOT use BelongsToTenant: that
 * trait throws when no tenant context is set, but an ownerless write is a valid
 * state here.
 *
 * Stamping (creating hook): tenant_id is taken from the subject's own client
 * when the subject is a tenant-owned model — so a global admin editing client
 * A's lead in the all-clients posture is still recorded against A — else the
 * active tenant context, else null (ownerless). It never throws.
 *
 * Append-only: UPDATE/DELETE are revoked at the DB (D-M7-5); nothing here
 * exposes a mutation path.
 *
 * @property int|null $tenant_id
 * @property-read Tenant|null $tenant
 */
class ActivityLog extends Activity
{
    /** @use HasFactory<ActivityLogFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (self $activity): void {
            if ($activity->tenant_id !== null) {
                return; // stamped explicitly (e.g. by a caller that knows the client)
            }

            $activity->tenant_id = self::resolveTenantId($activity);
        });
    }

    /**
     * The owning client, or null for ownerless/global rows. Drives the viewer's
     * client-attribution column.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Attribute the event to the subject's own client when the subject is a
     * tenant-owned model (the subject is already associated in memory by
     * Spatie's performedOn(), so this reads it with no extra query), else the
     * active tenant context, else null.
     */
    private static function resolveTenantId(self $activity): ?int
    {
        $subject = $activity->subject;

        if ($subject !== null && $subject->getAttribute('tenant_id') !== null) {
            return (int) $subject->getAttribute('tenant_id');
        }

        return TenantContext::id();
    }
}
