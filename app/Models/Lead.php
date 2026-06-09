<?php

namespace App\Models;

use App\Audit\LogsModelActivity;
use App\Enums\LeadStatus;
use App\Tenancy\BelongsToTenant;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A contact record an agent works (FR-LC01–04). Tenant-owned and bound to one
 * campaign. Its position in the fixed funnel is `status` (D-M4-3); its last
 * call outcome is the configurable `lastDisposition` row.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $campaign_id
 * @property string|null $name
 * @property string $phone
 * @property string|null $email
 * @property string|null $region
 * @property LeadStatus $status
 * @property int|null $last_disposition_id
 * @property int $attempts
 * @property array<string, mixed> $custom_fields
 */
class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use BelongsToTenant, HasFactory, LogsModelActivity;

    protected $fillable = [
        'campaign_id',
        'name',
        'phone',
        'email',
        'region',
        'status',
        'last_disposition_id',
        'attempts',
        'custom_fields',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LeadStatus::class,
            'attempts' => 'integer',
            'custom_fields' => 'array',
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
     * The last call outcome recorded against this lead (D-M4-3).
     *
     * @return BelongsTo<Disposition, $this>
     */
    public function lastDisposition(): BelongsTo
    {
        return $this->belongsTo(Disposition::class, 'last_disposition_id');
    }

    /**
     * @return array<int, string>
     */
    protected function activityLogAttributes(): array
    {
        return ['campaign_id', 'name', 'phone', 'email', 'region', 'status', 'last_disposition_id', 'attempts', 'custom_fields'];
    }

    protected function activityLogName(): string
    {
        return 'lead';
    }
}
