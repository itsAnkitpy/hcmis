<?php

namespace App\Audit;

use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Shared automatic-logging policy for the audited models (M7 D-M7-2). Wraps
 * Spatie's LogsActivity so every audited model gets the SAME shape in one place:
 *
 *  - logOnly(allowlist)   — only the curated columns are recorded, so adding a
 *                           sensitive column to a model never silently leaks it
 *                           into the log (this is how User keeps password,
 *                           remember_token and the 2FA secrets out — they are
 *                           simply never on the allowlist);
 *  - logOnlyDirty()       — record only what actually changed (before → after);
 *  - dontLogEmptyChanges()— a write that touched no audited column writes no row.
 *
 * Each model declares just its allowlist + per-domain log name. tenant_id
 * stamping happens centrally in the ActivityLog model's creating hook, not here.
 */
trait LogsModelActivity
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->activityLogAttributes())
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName($this->activityLogName());
    }

    /**
     * The curated allowlist of columns whose changes are recorded.
     *
     * @return array<int, string>
     */
    abstract protected function activityLogAttributes(): array;

    /**
     * The per-domain log name used to filter the viewer (e.g. lead, dnc, rbac).
     */
    abstract protected function activityLogName(): string;
}
