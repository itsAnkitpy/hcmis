<?php

namespace App\Filament\Resources\Tenants\RelationManagers;

use App\Enums\RoleName;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Per-tenant role surface (M3 §5.6, M2 review item #3).
 *
 * The Tenant edit page surfaces ONLY the spatie roles scoped to that tenant's
 * team id — never team 0. Read-only in Phase 1: the 5 per-client roles are
 * auto-provisioned by TenantObserver, and M2 §2 explicitly defers custom
 * per-client role *names* until a client actually demands them. Per-permission
 * editing lands later if needed; for now this view exists to prove the
 * scoping holds and to give HC admins a tenant-aware way to see what roles
 * apply.
 */
class RolesRelationManager extends RelationManager
{
    protected static string $relationship = 'roles';

    protected static ?string $title = 'Roles (per-tenant)';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Role')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        RoleName::TeamLeader->value => 'Team Leader',
                        RoleName::Qc->value => 'QC',
                        RoleName::Trainer->value => 'Trainer',
                        RoleName::Agent->value => 'Agent',
                        RoleName::ClientUser->value => 'Client User',
                        default => $state,
                    })
                    ->sortable(),
                TextColumn::make('team_id')
                    ->label('Team id (scope)')
                    ->badge(),
                TextColumn::make('permissions_count')
                    ->counts('permissions')
                    ->label('Permissions'),
            ])
            ->defaultSort('name')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
