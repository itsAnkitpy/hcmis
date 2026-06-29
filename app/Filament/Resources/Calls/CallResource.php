<?php

namespace App\Filament\Resources\Calls;

use App\Enums\CallDirection;
use App\Enums\CallOutcome;
use App\Filament\Resources\Calls\Pages\ListCalls;
use App\Filament\Resources\Calls\Pages\ViewCall;
use App\Filament\Resources\Calls\Tables\CallsTable;
use App\Models\Call;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Call Review (CR-1): a READ-ONLY list + view of call records (CDRs) with their
 * recordings. No form, no create/edit/delete — a call record is a historical fact
 * (deletion = the retention / erasure module). Visible only to the gated auditor
 * roles via CallPolicy (global + Team Leader + QC). Reads B3's `calls` table and
 * writes nothing. The recording itself is served by the gated stream route (CR-2),
 * never linked directly.
 */
class CallResource extends Resource
{
    protected static ?string $model = Call::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhone;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Call Review';

    protected static ?string $modelLabel = 'call';

    protected static ?int $navigationSort = 4;

    public static function table(Table $table): Table
    {
        return CallsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Call')
                ->columns(2)
                ->schema([
                    TextEntry::make('created_at')->label('When')->dateTime(),
                    TextEntry::make('tenant.name')->label('Client')->placeholder('—'),
                    TextEntry::make('direction')
                        ->badge()
                        ->formatStateUsing(fn (CallDirection $state): string => $state->label()),
                    TextEntry::make('outcome')
                        ->badge()
                        ->placeholder('—')
                        ->formatStateUsing(fn (?CallOutcome $state): string => $state?->label() ?? '—'),
                    TextEntry::make('agent.name')->label('Agent')->placeholder('—'),
                    TextEntry::make('campaign.name')->label('Campaign')->placeholder('—'),
                    TextEntry::make('from_number')->label('From')->placeholder('—'),
                    TextEntry::make('to_number')->label('To')->placeholder('—'),
                    TextEntry::make('duration_seconds')
                        ->label('Duration')
                        ->placeholder('—')
                        ->formatStateUsing(fn (?int $state): string => $state !== null ? gmdate('i:s', $state) : '—'),
                ]),
            Section::make('Recording')
                ->schema([
                    TextEntry::make('recording_path')
                        ->hiddenLabel()
                        ->placeholder('No recording')
                        ->formatStateUsing(fn (?string $state): string => $state
                            ? 'Recording available — use Play / Download above.'
                            : 'No recording for this call.'),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCalls::route('/'),
            'view' => ViewCall::route('/{record}'),
        ];
    }

    /** Read-only: a call record is written by the wrap-up (B3), never through the panel. */
    public static function canCreate(): bool
    {
        return false;
    }
}
