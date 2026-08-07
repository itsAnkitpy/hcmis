<?php

namespace App\Models;

use App\Audit\LogsModelActivity;
use App\Enums\TenantStatus;
use App\Tenancy\InvalidTenantTransitionException;
use App\Tenancy\Observers\TenantObserver;
use App\Tenancy\Settings\TenantSettings;
use App\Tenancy\Settings\TenantSettingsCast;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * A client account operated by HighlandConnect. This is the tenant itself —
 * it is NOT tenant-owned, so it does not use the BelongsToTenant trait.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property TenantStatus $status
 * @property Carbon|null $suspended_at
 * @property Carbon|null $archived_at
 * @property string|null $status_reason
 * @property int|null $ring_seconds
 * @property int|null $max_hold_seconds
 * @property TenantSettings $settings
 */
#[ObservedBy([TenantObserver::class])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, LogsModelActivity;

    protected $fillable = [
        'name',
        'slug',
        'status',
        'suspended_at',
        'archived_at',
        'status_reason',
        'ring_seconds',
        'max_hold_seconds',
        'settings',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'suspended_at' => 'datetime',
            'archived_at' => 'datetime',
            'ring_seconds' => 'integer',
            'max_hold_seconds' => 'integer',
            'settings' => TenantSettingsCast::class,
        ];
    }

    /**
     * How long ONE agent's phone rings for this client before we give up on them
     * and hand the caller back to the waiting room (B2.3b-i QD-7). Falls back to
     * the config default when the client has set nothing.
     */
    public function ringSeconds(): int
    {
        return $this->ring_seconds ?? (int) config('telephony.queue.ring_seconds');
    }

    /**
     * How long a caller may hold for this client before we stop waiting, end the
     * call, and write them to the missed-call list (B2.3b-i QD-7). Falls back to
     * the config default when the client has set nothing.
     */
    public function maxHoldSeconds(): int
    {
        return $this->max_hold_seconds ?? (int) config('telephony.queue.max_hold_seconds');
    }

    /**
     * The ONLY lifecycle gate (M3 §5.1). Every code path that changes a tenant's
     * status — Filament actions, console commands, observers, a future API —
     * calls this. Centralising it keeps the meaning of "suspended" and
     * "archived" from drifting and makes the audit trail honest.
     */
    public function transitionTo(TenantStatus $next, ?string $reason = null): self
    {
        $current = $this->status;

        if ($current === $next) {
            return $this;
        }

        if (! $current->canTransitionTo($next)) {
            throw new InvalidTenantTransitionException($current, $next);
        }

        return DB::transaction(function () use ($next, $reason): self {
            $this->status = $next;
            $this->status_reason = $reason;

            $this->suspended_at = $next === TenantStatus::Suspended ? now() : null;
            $this->archived_at = $next === TenantStatus::Archived ? now() : $this->archived_at;

            $this->save();

            return $this;
        });
    }

    public function isOperable(): bool
    {
        return $this->status->isOperable();
    }

    /**
     * The spatie roles scoped to this tenant's team (M3 §5.6). Powers the
     * RolesRelationManager on the Tenant edit page so each tenant's role
     * surface is visibly scoped to its own team id — not the global team 0.
     *
     * @return HasMany<Role, $this>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(Role::class, 'team_id');
    }

    /**
     * Inverse of User::tenants(). Powers the UsersRelationManager on the
     * Tenant edit page so HC admins can add / remove / re-role agents for
     * this client without leaving the tenant context.
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_tenant')->withTimestamps();
    }

    /**
     * The lifecycle + identity columns. `settings` is intentionally excluded:
     * it is a large nested object and its edits are lower-value for the audit
     * trail; the compliance-relevant Tenant events are the status transitions
     * (suspend / archive) captured here (D-M7-2).
     *
     * @return array<int, string>
     */
    protected function activityLogAttributes(): array
    {
        return ['name', 'slug', 'status', 'suspended_at', 'archived_at', 'status_reason', 'ring_seconds', 'max_hold_seconds'];
    }

    protected function activityLogName(): string
    {
        return 'tenant';
    }
}
