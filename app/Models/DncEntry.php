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
 * blocks() is the one question every caller asks of this list before dialing —
 * the console today, the dialer next (DIAL-1 A1). The NATIONAL scrub is still
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
     * Whether a (normalized) number is on the current client's do-not-call list
     * (M6 O1). Tenant-walled for free by BelongsToTenant + RLS, so it answers only
     * for the client whose context is running — another client's list never blocks
     * here. Presence is the block: expiry is stored but not enforced (D-M6-8), so
     * the safe compliance default is to block on any match.
     *
     * It lived as a private method on the agent console, so a background program
     * could not ask the same question. Both callers — the console and the coming
     * dialer (DIAL-1 A1) — now read the identical rule, which is the point: two
     * definitions of "must not call this person" is the one duplication in this
     * module with a legal consequence.
     */
    public static function blocks(string $phone): bool
    {
        return static::query()->where('phone', $phone)->exists();
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
