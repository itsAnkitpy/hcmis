<?php

namespace App\Filament\Resources\Users\Tables;

use App\Enums\RoleName;
use App\Filament\Pages\AgentDetail;
use App\Models\Call;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\Actions\SendUserInvite;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable()->sortable(),
                IconColumn::make('email_verified_at')
                    ->label('Verified')
                    ->boolean()
                    ->getStateUsing(fn (User $record): bool => $record->email_verified_at !== null),
                TextColumn::make('global_roles')
                    ->label('Global roles')
                    ->badge()
                    ->state(fn (User $record): array => self::globalRolesFor($record))
                    ->color('primary'),
                TextColumn::make('tenants_count')
                    ->label('Clients')
                    ->counts('tenants')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->filters([
                TernaryFilter::make('email_verified_at')
                    ->label('Email verified')
                    ->nullable()
                    ->placeholder('Any')
                    ->trueLabel('Verified')
                    ->falseLabel('Not verified'),
                SelectFilter::make('global_role')
                    ->label('Global role')
                    ->options(collect(RoleName::globals())
                        ->mapWithKeys(fn (RoleName $r): array => [$r->value => self::headline($r->value)])
                        ->all())
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        if ($value === null || $value === '') {
                            return $query;
                        }

                        return $query->whereIn('id', function ($q) use ($value) {
                            $q->select('model_has_roles.model_id')
                                ->from('model_has_roles')
                                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                                ->where('model_has_roles.model_type', User::class)
                                ->where('model_has_roles.team_id', 0)
                                ->where('roles.name', $value);
                        });
                    }),
                SelectFilter::make('tenant')
                    ->label('Member of client')
                    ->options(Tenant::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        if ($value === null || $value === '') {
                            return $query;
                        }

                        return $query->whereHas('tenants', fn (Builder $q) => $q->whereKey($value));
                    }),
                Filter::make('no_memberships')
                    ->label('Unassigned (no clients & no global role)')
                    ->query(fn (Builder $query) => $query
                        ->doesntHave('tenants')
                        ->whereNotIn('id', function ($q) {
                            $q->select('model_id')
                                ->from('model_has_roles')
                                ->where('model_type', User::class)
                                ->where('team_id', 0);
                        })),
            ])
            ->recordActions([
                Action::make('agent_detail')
                    ->label('Agent detail')
                    ->icon(Heroicon::OutlinedClock)
                    ->color('gray')
                    ->visible(fn (): bool => Gate::allows('viewAny', Call::class))
                    ->url(fn (User $record): string => AgentDetail::getUrl(['record' => $record->getKey()])),
                Action::make('resend_invite')
                    ->label('Resend invite')
                    ->icon(Heroicon::OutlinedEnvelope)
                    ->color('gray')
                    ->visible(fn (User $record): bool => $record->email_verified_at === null)
                    ->requiresConfirmation()
                    ->modalDescription('Sends a fresh signed invite link to this user. Any previous unused link stops working as soon as it expires (no need to revoke).')
                    ->action(function (User $record): void {
                        $notification = Notification::make();

                        if (SendUserInvite::trySend($record)) {
                            $notification->success()
                                ->title('Invite resent')
                                ->body("A fresh invite email is on its way to {$record->email}.");
                        } else {
                            $notification->danger()
                                ->title('Invite could not be sent')
                                ->body('Sending failed — check the mail configuration and try again.');
                        }

                        $notification->send();
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }

    /**
     * @return array<int, string>
     */
    private static function globalRolesFor(User $user): array
    {
        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $user->getKey())
            ->where('model_has_roles.model_type', User::class)
            ->where('model_has_roles.team_id', 0)
            ->pluck('roles.name')
            ->map(fn (string $name): string => self::headline($name))
            ->all();
    }

    private static function headline(string $value): string
    {
        return str_replace('_', ' ', ucwords($value, '_'));
    }
}
