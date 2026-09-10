<?php

namespace App\Models;

use App\Actions\SeedCampaignDispositions;
use App\Audit\LogsModelActivity;
use App\Enums\CampaignCategory;
use App\Enums\CampaignTemplate;
use App\Enums\DialMode;
use App\Tenancy\BelongsToTenant;
use Database\Factories\CampaignFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A calling drive a client runs (FR-LC01). Tenant-owned: every campaign carries
 * a tenant_id and is filtered by RLS + the app-layer scope. Leads belong to a
 * campaign; the campaign's template drives its starter disposition set (M4.E).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property CampaignTemplate $template
 * @property DialMode $dial_mode
 * @property bool $is_dialing
 * @property CampaignCategory $category
 * @property string|null $caller_id
 * @property string $dial_start_time
 * @property string $dial_end_time
 * @property int|null $max_attempts
 * @property bool $is_active
 * @property array<int, array<string, mixed>> $custom_fields
 */
class Campaign extends Model
{
    /** @use HasFactory<CampaignFactory> */
    use BelongsToTenant, HasFactory, LogsModelActivity;

    /** CP-4: the per-client bucket an inbound caller with no list entry lands in. */
    public const INBOUND_NAME = 'Inbound';

    protected $fillable = [
        'name',
        'template',
        'dial_mode',
        'is_dialing',
        'category',
        'caller_id',
        'dial_start_time',
        'dial_end_time',
        'max_attempts',
        'is_active',
        'custom_fields',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'template' => CampaignTemplate::class,
            'dial_mode' => DialMode::class,
            'is_dialing' => 'boolean',
            'category' => CampaignCategory::class,
            'max_attempts' => 'integer',
            // 🔴 dial_start_time / dial_end_time are deliberately NOT cast (S118).
            // They are wall-clock times with no date, so casting them to datetime
            // makes Filament convert them against the panel's reading zone — the
            // bug that turned 09:30 into 04:00 on the business-hours form. Postgres
            // hands them back as 'HH:MM:SS' strings, which is what they are.
            'is_active' => 'boolean',
            'custom_fields' => 'array',
        ];
    }

    /**
     * CP-4 — the campaign an inbound caller nobody has on a list belongs to.
     *
     * `leads.campaign_id` is NOT NULL, so a customer created from an inbound call
     * has to land somewhere. A8: on their floor these callers already sit under
     * "inbound", and which campaign that means varies client to client — which is
     * exactly what a per-client campaign gives. Found-or-created on first use, so
     * no seeder and no migration on a live table.
     *
     * `CustomerCareCallback` as the template deliberately: it is the closest
     * existing shape, and reusing it means SeedCampaignDispositions hands the new
     * campaign a real disposition set with no new enum case and no config change.
     *
     * Tenant-scoped both ways — the lookup is filtered by TenantScope and the
     * create is stamped by BelongsToTenant, so one client can never be handed
     * another client's inbound bucket.
     */
    public static function inboundFallback(): self
    {
        $campaign = self::query()->firstOrNew(['name' => self::INBOUND_NAME]);

        if ($campaign->exists) {
            return $campaign;
        }

        $campaign->fill([
            'template' => CampaignTemplate::CustomerCareCallback,
            'is_active' => true,
        ])->save();

        SeedCampaignDispositions::run($campaign);

        return $campaign;
    }

    /**
     * @return HasMany<Lead, $this>
     */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /**
     * @return HasMany<Disposition, $this>
     */
    public function dispositions(): HasMany
    {
        return $this->hasMany(Disposition::class);
    }

    /**
     * @return HasMany<Script, $this>
     */
    public function scripts(): HasMany
    {
        return $this->hasMany(Script::class);
    }

    /**
     * @return array<int, string>
     */
    protected function activityLogAttributes(): array
    {
        return ['name', 'template', 'dial_mode', 'is_dialing', 'category', 'caller_id', 'dial_start_time', 'dial_end_time', 'max_attempts', 'is_active', 'custom_fields'];
    }

    protected function activityLogName(): string
    {
        return 'campaign';
    }
}
