<?php

namespace App\Models;

use App\Audit\LogsModelActivity;
use App\Enums\ScriptType;
use App\Tenancy\BelongsToTenant;
use Database\Factories\ScriptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What an agent says on a call (BRD §6.1) — opening / objection / closing.
 * Promoted from settings JSON to a row (D-M4-2). Tenant-wide when campaign_id
 * is null, or scoped to one campaign when set.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $campaign_id
 * @property ScriptType $type
 * @property string $content
 */
class Script extends Model
{
    /** @use HasFactory<ScriptFactory> */
    use BelongsToTenant, HasFactory, LogsModelActivity;

    protected $fillable = [
        'campaign_id',
        'type',
        'content',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ScriptType::class,
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
        return ['campaign_id', 'type', 'content'];
    }

    protected function activityLogName(): string
    {
        return 'script';
    }
}
