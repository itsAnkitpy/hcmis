<?php

namespace App\Models;

use App\Audit\LogsModelActivity;
use App\Tenancy\BelongsToTenant;
use Database\Factories\BreakCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A named break type an agent can pick when going on break (BK-1) — Bio Break,
 * Lunch Break, Tea Break… Tenant-owned and admin-managed, copying the
 * Disposition pattern: stable code, editable label, sort order, seeded
 * defaults, every edit audit-logged. Seeded per tenant from
 * config/hcims.php break_category_defaults by SeedBreakCategories.
 *
 * Deactivated, never deleted — status-history rows (BK-2) point at a category
 * id and must keep resolving forever.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $code
 * @property string $label
 * @property int|null $time_limit_minutes
 * @property bool $is_active
 * @property int $sort_order
 */
class BreakCategory extends Model
{
    /** @use HasFactory<BreakCategoryFactory> */
    use BelongsToTenant, HasFactory, LogsModelActivity;

    protected $fillable = [
        'code',
        'label',
        'time_limit_minutes',
        'is_active',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'time_limit_minutes' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function activityLogAttributes(): array
    {
        return ['code', 'label', 'time_limit_minutes', 'is_active', 'sort_order'];
    }

    protected function activityLogName(): string
    {
        return 'break_category';
    }
}
