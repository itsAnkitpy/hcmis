<?php

namespace App\Models;

use App\Audit\LogsModelActivity;
use App\Tenancy\BelongsToTenant;
use Database\Factories\DispositionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A call-outcome option an agent can record (D-M4-2). Promoted from the M3
 * settings JSON to a real row so a lead's "last outcome" can point at a stable
 * id and outcomes can be reported on. Tenant-wide when campaign_id is null, or
 * scoped to one campaign when set.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $campaign_id
 * @property string $code
 * @property string $label
 * @property bool $is_contact
 * @property bool $is_sale
 * @property int $sort_order
 */
class Disposition extends Model
{
    /** @use HasFactory<DispositionFactory> */
    use BelongsToTenant, HasFactory, LogsModelActivity;

    /**
     * The disposition `code` that means "the customer asked to be called back"
     * (M4). Outbound wrap-up keys callback capture off this code — labels are
     * client-editable, the code is the stable identifier. Seeded in the
     * OutboundSales / CustomerCare / Edtech templates (not COD / NDR); a campaign
     * without it simply never offers callback scheduling. The single seam: change
     * detection (e.g. to an is_callback flag) in one place.
     */
    public const CALLBACK_CODE = 'CALLBACK';

    protected $fillable = [
        'campaign_id',
        'code',
        'label',
        'is_contact',
        'is_sale',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_contact' => 'boolean',
            'is_sale' => 'boolean',
            'sort_order' => 'integer',
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
        return ['campaign_id', 'code', 'label', 'is_contact', 'is_sale', 'sort_order'];
    }

    protected function activityLogName(): string
    {
        return 'disposition';
    }
}
