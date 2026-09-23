<?php

declare(strict_types=1);

namespace App\Models;

use App\Audit\LogsModelActivity;
use App\Tenancy\BelongsToTenant;
use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A group of a client's agents a menu key can ring — "Sales", "Hindi"
 * (inbound-audio slice 7). A plain list: no levels, no ranks (D1).
 *
 * Switched off, never deleted (D9, the BK-1 rule): calls point at a department and
 * the database refuses to delete one any call remembers.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property bool $is_active
 */
class Department extends Model
{
    /** @use HasFactory<DepartmentFactory> */
    use BelongsToTenant, HasFactory, LogsModelActivity;

    protected $fillable = [
        // Head office manages departments with NO client in context (as with Menu), so
        // which client one is for arrives on the form rather than from the tenant wall.
        'tenant_id',
        'name',
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
     * The agents in this department. The membership row carries the department's own
     * client, because head office attaches members with no client in context and the
     * row is walled by RLS like every other tenant-owned table.
     *
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        $members = $this->belongsToMany(User::class)->withTimestamps();

        // A blank instance (a count query, the create form) has no client yet; the pivot
        // value is only needed once there is a row to attach to.
        return $this->tenant_id === null ? $members : $members->withPivotValue('tenant_id', $this->tenant_id);
    }

    /**
     * Whether a call, a ring-time note or a menu key points at this department. The
     * database refuses to delete one a call points at (D9), and a key would ring nobody,
     * so the screen hides Delete rather than letting either surface as an error.
     */
    public function isInUse(): bool
    {
        return Call::query()->where('department_id', $this->id)->exists()
            || CallHandoff::query()->where('department_id', $this->id)->exists()
            || $this->menusUsingIt()->isNotEmpty();
    }

    /**
     * The menus with a key that rings this department. It cannot be switched off or
     * deleted while any does (D9) — the key would ring nobody.
     *
     * @return Collection<int, Menu>
     */
    public function menusUsingIt(): Collection
    {
        return Menu::query()
            ->whereJsonContains('options', [['department_id' => $this->id]])
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<int, string>
     */
    protected function activityLogAttributes(): array
    {
        return ['name', 'is_active'];
    }

    protected function activityLogName(): string
    {
        return 'department';
    }
}
