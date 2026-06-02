<?php

namespace App\Filament\Resources\Leads\Tables;

use App\Enums\LeadStatus;
use App\Filament\Support\ClientColumn;
use App\Models\Campaign;
use App\Models\Lead;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class LeadsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // C3: eager-load the relationships the columns read, so the list is
            // not N+1 once filters grow the rows on screen. tenant is loaded only
            // in the all-clients posture, where the Client column renders.
            ->modifyQueryUsing(fn (Builder $query): Builder => ClientColumn::eagerLoad($query->with(['campaign', 'lastDisposition'])))
            ->columns([
                ClientColumn::make(),
                TextColumn::make('name')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('phone')
                    ->searchable(),
                TextColumn::make('campaign.name')
                    ->label('Campaign')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (LeadStatus $state): string => $state->label())
                    ->color(fn (LeadStatus $state): string => match ($state) {
                        LeadStatus::New => 'gray',
                        LeadStatus::InProgress => 'info',
                        LeadStatus::Contacted => 'warning',
                        LeadStatus::Closed => 'success',
                    }),
                TextColumn::make('lastDisposition.label')
                    ->label('Last disposition')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('region')
                    ->toggleable(),
                TextColumn::make('attempts')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(LeadStatus::cases())
                        ->mapWithKeys(fn (LeadStatus $s): array => [$s->value => $s->label()])
                        ->all()),
                SelectFilter::make('region')
                    ->options(fn (): array => Lead::query()
                        ->whereNotNull('region')
                        ->distinct()
                        ->orderBy('region')
                        ->pluck('region', 'region')
                        ->all()),
                Filter::make('attempts')
                    ->schema([
                        Select::make('bucket')
                            ->label('Attempts')
                            ->options([
                                'none' => 'No attempts',
                                'low' => '1–2',
                                'mid' => '3–5',
                                'high' => '6 or more',
                            ])
                            ->native(false),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['bucket'] ?? null,
                        fn (Builder $q, string $bucket): Builder => match ($bucket) {
                            'none' => $q->where('attempts', 0),
                            'low' => $q->whereBetween('attempts', [1, 2]),
                            'mid' => $q->whereBetween('attempts', [3, 5]),
                            'high' => $q->where('attempts', '>=', 6),
                            default => $q,
                        },
                    )),
                Filter::make('age')
                    ->schema([
                        Select::make('bucket')
                            ->label('Lead age')
                            ->options([
                                'today' => 'Today',
                                'week' => '7 days or newer',
                                'month' => '30 days or newer',
                                'older' => 'Older than 30 days',
                            ])
                            ->native(false),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['bucket'] ?? null,
                        fn (Builder $q, string $bucket): Builder => match ($bucket) {
                            'today' => $q->whereDate('created_at', today()),
                            'week' => $q->where('created_at', '>=', now()->subDays(7)),
                            'month' => $q->where('created_at', '>=', now()->subDays(30)),
                            'older' => $q->where('created_at', '<', now()->subDays(30)),
                            default => $q,
                        },
                    )),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                // Single-lead move (FR-LC04). Custom actions are not auto-authorized,
                // so gate it on the same 'update' ability as editing.
                Action::make('moveToCampaign')
                    ->label('Move')
                    ->icon(Heroicon::OutlinedArrowsRightLeft)
                    ->authorize('update')
                    ->schema([
                        Select::make('campaign_id')
                            ->label('Move to campaign')
                            ->options(fn (): array => self::campaignOptions())
                            ->required()
                            ->searchable(),
                    ])
                    ->action(function (Lead $record, array $data): void {
                        self::moveLeads(collect([$record]), (int) $data['campaign_id']);
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Bulk move (FR-LC04). authorizeIndividualRecords keeps it to
                    // leads the user may update.
                    BulkAction::make('moveToCampaign')
                        ->label('Move to campaign')
                        ->icon(Heroicon::OutlinedArrowsRightLeft)
                        ->authorizeIndividualRecords('update')
                        ->schema([
                            Select::make('campaign_id')
                                ->label('Move to campaign')
                                ->options(fn (): array => self::campaignOptions())
                                ->required()
                                ->searchable(),
                        ])
                        ->action(fn (Collection $records, array $data) => self::moveLeads($records, (int) $data['campaign_id']))
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Campaigns the current client can move leads into. Tenant-scoped via the
     * active client's wall (RLS + the app-layer scope).
     *
     * @return array<int, string>
     */
    private static function campaignOptions(): array
    {
        return Campaign::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * Reassign each lead to the target campaign (FR-LC04). The target is resolved
     * through the tenant wall, so a tampered cross-tenant id resolves to null and
     * the move is rejected — tenant_id itself stays immutable (BelongsToTenant).
     *
     * @param  \Illuminate\Support\Collection<int, Lead>  $leads
     */
    private static function moveLeads(\Illuminate\Support\Collection $leads, int $campaignId): void
    {
        $target = Campaign::find($campaignId);

        if (! $target) {
            Notification::make()
                ->title('That campaign is not in this client.')
                ->danger()
                ->send();

            return;
        }

        $leads->each(fn (Lead $lead) => $lead->update(['campaign_id' => $target->id]));

        Notification::make()
            ->title($leads->count() === 1 ? "Lead moved to {$target->name}" : "{$leads->count()} leads moved to {$target->name}")
            ->success()
            ->send();
    }
}
