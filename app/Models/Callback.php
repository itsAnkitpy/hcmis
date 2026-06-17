<?php

namespace App\Models;

use App\Audit\LogsModelActivity;
use App\Enums\CallbackStatus;
use App\Tenancy\BelongsToTenant;
use Database\Factories\CallbackFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A "call me later" the agent captured at wrap-up (M4 D1). Tenant-owned, bound
 * to one lead and its campaign. v1 is STICKY: owner_agent_id is the agent who
 * took it, and only they see it in "my due callbacks". owner_agent_id = null is
 * the pooled (any-agent) shape reserved for B2 — same table, no data migration.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $lead_id
 * @property int $campaign_id
 * @property Carbon $scheduled_at
 * @property int|null $owner_agent_id
 * @property CallbackStatus $status
 * @property string|null $notes
 */
class Callback extends Model
{
    /** @use HasFactory<CallbackFactory> */
    use BelongsToTenant, HasFactory, LogsModelActivity;

    protected $fillable = [
        'lead_id',
        'campaign_id',
        'scheduled_at',
        'owner_agent_id',
        'status',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'status' => CallbackStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * The agent who owns this callback (sticky v1). Null = pooled (B2).
     *
     * @return BelongsTo<User, $this>
     */
    public function ownerAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_agent_id');
    }

    /**
     * @return array<int, string>
     */
    protected function activityLogAttributes(): array
    {
        return ['lead_id', 'campaign_id', 'scheduled_at', 'owner_agent_id', 'status', 'notes'];
    }

    protected function activityLogName(): string
    {
        return 'callback';
    }
}
