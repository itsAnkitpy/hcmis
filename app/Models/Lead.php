<?php

namespace App\Models;

use App\Audit\LogsModelActivity;
use App\Enums\LeadStatus;
use App\Support\PhoneNumber;
use App\Tenancy\BelongsToTenant;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
     * Every lead phone is stored in ONE spelling — the DncEntry mutator pattern
     * (M6 D-M6-5), which leads never got. Import normalized on write; hand-entry
     * did not, so `999-123 4567` and `9991234567` could sit side by side as two
     * leads for one person, and screen-pop's exact match missed the formatted one
     * entirely (it normalizes the INCOMING number only).
     *
     * Placed on the model, not on a form or an importer, because that is the one
     * door every write path already goes through — Filament create/edit, the
     * importer's Lead::create, and the factory. There are no raw SQL inserts.
     *
     * This is also what makes the unique (tenant_id, phone) rule meaningful: to
     * the database those two spellings are different strings, so the constraint
     * alone would never have caught them.
     */
    protected function phone(): Attribute
    {
        return Attribute::make(
            set: fn (mixed $value): ?string => PhoneNumber::normalize($value),
        );
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
     * Scheduled "call me later" rows for this lead (M4). A pending one parks the
     * lead out of the normal preview — it returns via the agent's due-list.
     *
     * @return HasMany<Callback, $this>
     */
    public function callbacks(): HasMany
    {
        return $this->hasMany(Callback::class);
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
