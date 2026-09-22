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
 * @property int|null $menu_id
 * @property bool $is_active
 */
class PhoneNumber extends Model
{
    /** @use HasFactory<PhoneNumberFactory> */
    use BelongsToTenant, HasFactory, LogsModelActivity;

    protected $fillable = [
        'number',
        'campaign_id',
        'menu_id',
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
     * The spoken menu that answers this number, or null for today's behaviour — straight
     * to the agent board (inbound-audio AU-18).
     *
     * @return BelongsTo<Menu, $this>
     */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    /**
     * The campaign that owns a number a caller rang (call-export.md CE-6), or null
     * when the number is unknown or nobody has assigned it a campaign.
     *
     * 🔴 SCOPED TO THE CURRENT CLIENT, deliberately NOT NumberDirectory. That lookup
     * is company-blind on purpose, because at call-arrival time finding the owning
     * client IS the question. Every caller of this method already knows the client,
     * and a company-blind read could attach another client's campaign to our row —
     * the one mistake that is worse than a blank column.
     *
     * Lives here rather than on either caller because BOTH row writers need it: the
     * agent's console when a call is answered, and CallToAgentFlow when nobody
     * answers. One copy, one set of reasoning.
     */
    public static function campaignIdFor(?string $number): ?int
    {
        if ($number === null) {
            return null;
        }

        $campaignId = static::query()->where('number', $number)->value('campaign_id');

        return $campaignId === null ? null : (int) $campaignId;
    }

    /**
     * @return array<int, string>
     */
    protected function activityLogAttributes(): array
    {
        return ['number', 'campaign_id', 'menu_id', 'is_active'];
    }

    protected function activityLogName(): string
    {
        return 'phone_number';
    }
}
