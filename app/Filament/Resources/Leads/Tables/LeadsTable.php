<?php

namespace App\Filament\Resources\Leads\Tables;

use App\Enums\DncSource;
use App\Enums\LeadStatus;
use App\Filament\Support\ClientColumn;
use App\Models\Campaign;
use App\Models\DncEntry;
use App\Models\Lead;
use App\Support\PhoneNumber;
use App\Tenancy\TenantContext;
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
                // Add this lead's number to the client's DNC list (M6 follow-up).
                // One click + confirm, no form. Gated like the import action.
                Action::make('addToDnc')
                    ->label('Add to DNC')
                    ->icon(Heroicon::OutlinedPhoneXMark)
                    ->color('danger')
                    ->visible(fn (): bool => self::canAddToDnc())
                    ->requiresConfirmation()
                    ->modalHeading('Add to do-not-call list')
                    ->modalDescription("This number is added to this client's do-not-call list. The lead itself is left unchanged.")
                    ->modalSubmitActionLabel('Add to DNC')
                    ->action(function (Lead $record): void {
                        self::addLeadsToDnc(collect([$record]));
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
                    // Bulk add to DNC (M6 follow-up). Already-listed numbers are
                    // skipped and reported; the leads themselves are unchanged.
                    BulkAction::make('addToDnc')
                        ->label('Add to DNC')
                        ->icon(Heroicon::OutlinedPhoneXMark)
                        ->color('danger')
                        ->visible(fn (): bool => self::canAddToDnc())
                        ->requiresConfirmation()
                        ->modalHeading('Add to do-not-call list')
                        ->modalDescription("These numbers are added to this client's do-not-call list. Numbers already listed are skipped. The leads themselves are left unchanged.")
                        ->modalSubmitActionLabel('Add to DNC')
                        ->action(fn (Collection $records) => self::addLeadsToDnc($records))
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

    /**
     * Whether the current user may add numbers to this client's DNC list. Like
     * the import action, it needs a current client (the DNC entry is stamped to
     * it) plus the DncEntry create ability (D-M4-5 write roles). Hidden for
     * global staff in all-clients mode — they pick a client first.
     */
    private static function canAddToDnc(): bool
    {
        return TenantContext::has()
            && (auth()->user()?->can('create', DncEntry::class) ?? false);
    }

    /**
     * Add each lead's phone to the current client's do-not-call list (M6
     * follow-up, FR-LC05). Idempotent: the (tenant_id, phone) unique rule means a
     * number is listed once, so a re-add is skipped, not an error.
     *
     * The lead itself is left unchanged — "on DNC" and lead status are
     * deliberately kept as separate facts. This is a changeable call: closing the
     * lead too (status -> Closed) would be a one-line addition here if the client
     * workflow ever wants it.
     *
     * @param  \Illuminate\Support\Collection<int, Lead>  $leads
     */
    private static function addLeadsToDnc(\Illuminate\Support\Collection $leads): void
    {
        // Defensive: visible() already gates the action, but never write a DNC
        // entry without a current client + the create ability (mirrors import).
        if (! self::canAddToDnc()) {
            return;
        }

        $added = 0;
        $already = 0;

        foreach ($leads as $lead) {
            $phone = PhoneNumber::normalize($lead->phone);

            if ($phone === null) {
                continue; // no usable number to suppress
            }

            // firstOrCreate is tenant-scoped by the active client, so it finds
            // this client's existing entry (skip) or creates a new one stamped to
            // the client by BelongsToTenant.
            $entry = DncEntry::firstOrCreate(
                ['phone' => $phone],
                ['source' => DncSource::CustomerRequest],
            );

            $entry->wasRecentlyCreated ? $added++ : $already++;
        }

        Notification::make()
            ->title(self::dncSummary($added, $already))
            ->success()
            ->send();
    }

    /** Plain-language summary of an add-to-DNC run. */
    private static function dncSummary(int $added, int $already): string
    {
        if ($added > 0 && $already > 0) {
            return "Added {$added} to the DNC list; {$already} already listed.";
        }

        if ($added > 0) {
            return $added === 1 ? 'Number added to the DNC list.' : "{$added} numbers added to the DNC list.";
        }

        return $already === 1
            ? 'That number is already on the DNC list.'
            : "Those {$already} numbers were already on the DNC list.";
    }
}
