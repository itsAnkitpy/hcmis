<?php

namespace App\Models;

use App\Audit\LogsModelActivity;
use App\Tenancy\BelongsToTenant;
use Database\Factories\PhoneNumberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One phone number a client owns (B2.3a ND-2). The row that answers "which client
 * is this call for?" when a call arrives on it — the replacement for the single
 * hardcoded client in the dialplan.
 *
 * Tenant-owned like every other operational table, but read company-blind by
 * NumberDirectory at call time: finding the owner is the whole point, so the
 * lookup cannot already know one.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $number
 * @property int|null $campaign_id
 * @property bool $is_active
 */
class PhoneNumber extends Model
{
    /** @use HasFactory<PhoneNumberFactory> */
    use BelongsToTenant, HasFactory, LogsModelActivity;

    protected $fillable = [
        'number',
        'campaign_id',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * @return array<int, string>
     */
    protected function activityLogAttributes(): array
    {
        return ['number', 'campaign_id', 'is_active'];
    }

    protected function activityLogName(): string
    {
        return 'phone_number';
    }
}
