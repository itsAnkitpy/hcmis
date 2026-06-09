<?php

namespace App\Models;

use App\Audit\LogsModelActivity;
use App\Enums\DncSource;
use App\Support\PhoneNumber;
use App\Tenancy\BelongsToTenant;
use Database\Factories\DncEntryFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A phone number on one client's own do-not-call list (FR-LC05). Tenant-scoped
 * via BelongsToTenant — every row belongs to exactly one client. The national
 * TRAI register is a separate, non-tenant table (national_dnc_entries), so this
 * model never holds a "global" row (M6 D-M6-1).
 *
 * Data only: nothing here blocks a dial — scrub-before-dial enforcement is
 * Phase 3. expires_at is stored and displayed but not enforced (D-M6-8).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $phone
 * @property DncSource $source
 * @property string|null $reason
 * @property Carbon|null $expires_at
 * @property-read string $status
 */
class DncEntry extends Model
{
    /** @use HasFactory<DncEntryFactory> */
    use BelongsToTenant, HasFactory, LogsModelActivity;

    protected $fillable = [
        'phone',
        'source',
        'reason',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => DncSource::class,
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Store the number in the normalized form the whole system matches on, so
     * (tenant_id, phone) uniqueness and future Phase-3 lead-scrubbing stay
     * reliable no matter how it was typed (D-M6-4/-5).
     */
    protected function phone(): Attribute
    {
        return Attribute::make(
            set: fn (mixed $value): ?string => PhoneNumber::normalize($value),
        );
    }

    /**
     * Derived Active / Expired label for the list view. expires_at is shown and
     * labelled but not enforced in Phase 1 — nothing auto-removes an expired row
     * (D-M6-8).
     */
    protected function status(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->expires_at?->isPast() ? 'Expired' : 'Active',
        );
    }

    /**
     * @return array<int, string>
     */
    protected function activityLogAttributes(): array
    {
        return ['phone', 'source', 'reason', 'expires_at'];
    }

    protected function activityLogName(): string
    {
        return 'dnc';
    }
}
