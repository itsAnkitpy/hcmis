<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Tenancy\TenantContext;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;

/**
 * The owning-client column for tenant-owned resource tables (campaigns, leads,
 * dispositions, scripts). Gives global HC staff in the "All clients" posture the
 * client attribution they otherwise lack — every row could belong to a different
 * tenant and the rest of the columns don't say which.
 *
 * Shown ONLY in the cross-tenant posture. For a client-scoped view every row is
 * the same client, so the column is hidden as redundant noise. shouldShow() is
 * the single predicate both the column visibility and the eager-load key off, so
 * the two can never drift apart.
 */
class ClientColumn
{
    /**
     * The "Client" column. Bind to tenant.name; visible only across clients.
     */
    public static function make(): TextColumn
    {
        return TextColumn::make('tenant.name')
            ->label('Client')
            ->sortable()
            ->visible(fn (): bool => self::shouldShow());
    }

    /**
     * Eager-load the tenant relation only when the column will render — the
     * client-scoped view (column hidden) pays nothing, the all-clients view is
     * not N+1. Drop into a table's modifyQueryUsing().
     */
    public static function eagerLoad(Builder $query): Builder
    {
        return $query->when(self::shouldShow(), fn (Builder $q): Builder => $q->with('tenant'));
    }

    /**
     * Whether client attribution is meaningful for the current request — true
     * only in the cross-tenant ("All clients") posture (TenantContext bypass on,
     * no single client pinned).
     */
    public static function shouldShow(): bool
    {
        return TenantContext::isCrossTenant();
    }
}
