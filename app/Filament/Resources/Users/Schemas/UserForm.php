<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\RoleName;
use App\Models\User;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * Edit-side form for the global Users resource (M3 B.5.3).
 *
 * Identity is freely editable. Email-verified is a Toggle backed by the
 * email_verified_at timestamp (set/cleared via mutate hooks on EditUser).
 * Global roles are managed via a CheckboxList → write to model_has_roles
 * with team_id = 0 on save (handled in EditUser too).
 *
 * Per-tenant memberships live in the TenantsRelationManager tab on this page.
 * Password reset / invite happens via Checkpoint C; not exposed here.
 */
class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identity')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('Email address')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(User::class, 'email', ignoreRecord: true),
                    ])
                    ->columns(2),

                Section::make('Verification')
                    ->description('Toggle to mark this user as email-verified. Checkpoint C wires the invite flow that does this automatically.')
                    ->schema([
                        Toggle::make('email_verified')
                            ->label('Email verified')
                            ->dehydrated(false),
                    ])
                    ->hiddenOn('create'),

                Section::make('Global roles')
                    ->description('Held at the global team (id 0). A user with any global role operates across all clients via the audited cross() path and holds no per-tenant membership.')
                    ->schema([
                        CheckboxList::make('global_roles')
                            ->label('')
                            ->options(collect(RoleName::globals())
                                ->mapWithKeys(fn (RoleName $r): array => [$r->value => Str::headline($r->value)])
                                ->all())
                            ->columns(3)
                            ->dehydrated(false),
                    ])
                    ->hiddenOn('create'),
            ]);
    }
}
