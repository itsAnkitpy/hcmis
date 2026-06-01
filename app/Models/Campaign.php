<?php

namespace App\Models;

use App\Enums\CampaignTemplate;
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
 * @property bool $is_active
 * @property array<int, array<string, mixed>> $custom_fields
 */
class Campaign extends Model
{
    /** @use HasFactory<CampaignFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'name',
        'template',
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
            'is_active' => 'boolean',
            'custom_fields' => 'array',
        ];
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
}
