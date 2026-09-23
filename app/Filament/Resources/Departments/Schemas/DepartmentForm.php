<?php

declare(strict_types=1);

namespace App\Filament\Resources\Departments\Schemas;

use App\Models\Department;
use App\Models\User;
use App\Tenancy\TenantContext;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

/**
 * One department (inbound-audio slice 7). Name and switch are head office's; members
 * are head office's and team leaders' (D2). A team leader's name and switch are shown
 * disabled AND not dehydrated, so a crafted request cannot write them either.
 */
class DepartmentForm
{
    public static function configure(Schema $schema): Schema
    {
        $headOfficeOnly = fn (): bool => ! auth()->user()?->operatesGlobally();

        return $schema
            ->components([
                // Head office has no client in context (see DepartmentResource).
                Select::make('tenant_id')
                    ->label('Client')
                    ->relationship('tenant', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live()
                    ->visible(fn (): bool => TenantContext::isCrossTenant())
                    ->disabledOn('edit'),

                TextInput::make('name')
                    ->required()
                    ->maxLength(120)
                    ->disabled($headOfficeOnly)
                    ->dehydrated(fn (): bool => (bool) auth()->user()?->operatesGlobally())
                    ->helperText('Callers never hear this. Staff see it on menu keys and on each call.'),

                Toggle::make('is_active')
                    ->label('Switched on')
                    ->default(true)
                    ->disabled($headOfficeOnly)
                    ->dehydrated(fn (): bool => (bool) auth()->user()?->operatesGlobally())
                    // D9: switching off a department a key rings would leave that key
                    // ringing nobody. The message names the menu so head office knows
                    // where to go first.
                    ->rules([
                        fn (?Department $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                            if ($record === null || (bool) $value) {
                                return;
                            }

                            $menus = $record->menusUsingIt()->pluck('name');

                            if ($menus->isNotEmpty()) {
                                $fail('A key on '.$menus->join(', ', ' and ').' rings this department. Change that key first.');
                            }
                        },
                    ])
                    ->helperText('A switched-off department cannot be picked for a menu key, and keeps its name on past calls.'),

                // 🔴 THE DEPARTMENT'S OWN CLIENT, not the request's: head office has none.
                // "An agent" here means someone at this client with a phone — exactly the
                // people the router can ring (AgentRouter::reserveFreeAgent).
                Select::make('members')
                    ->label('Agents')
                    ->multiple()
                    ->relationship(
                        'members',
                        'name',
                        fn (Builder $query, Get $get, ?Department $record): Builder => self::agentsOf(
                            $query,
                            $record?->tenant_id ?? $get('tenant_id') ?? TenantContext::id(),
                        ),
                    )
                    // The picker only OFFERS this client's agents; this refuses a crafted
                    // request naming anyone else.
                    ->rule(fn (Get $get, ?Department $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                        $ids = array_map('intval', (array) $value);
                        $allowed = self::agentsOf(User::query(), $record?->tenant_id ?? $get('tenant_id') ?? TenantContext::id())
                            ->whereKey($ids)
                            ->count();

                        if ($allowed !== count(array_unique($ids))) {
                            $fail('Pick only this client\'s agents.');
                        }
                    })
                    ->searchable()
                    ->preload()
                    ->helperText('An agent may be in several departments.'),
            ]);
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    private static function agentsOf(Builder $query, mixed $tenantId): Builder
    {
        return $query
            ->whereNotNull('sip_extension')
            ->whereHas('tenants', fn (Builder $tenants) => $tenants->whereKey($tenantId));
    }
}
