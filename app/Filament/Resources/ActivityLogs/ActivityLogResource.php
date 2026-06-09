<?php

namespace App\Filament\Resources\ActivityLogs;

use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Filament\Resources\ActivityLogs\Pages\ViewActivityLog;
use App\Filament\Resources\ActivityLogs\Tables\ActivityLogsTable;
use App\Models\ActivityLog;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * The audit-log viewer (M7 D-M7-4): read-only list + view + filters. No form,
 * no create/edit/delete pages or actions — immutability is reinforced at the UI
 * (this resource), the policy (ActivityLogPolicy), and the DB (REVOKE). Visible
 * only to the gated roles via the policy.
 */
class ActivityLogResource extends Resource
{
    protected static ?string $model = ActivityLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Compliance';

    protected static ?string $navigationLabel = 'Audit log';

    protected static ?string $modelLabel = 'audit entry';

    protected static ?string $pluralModelLabel = 'audit log';

    public static function table(Table $table): Table
    {
        return ActivityLogsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Event')
                ->columns(2)
                ->schema([
                    TextEntry::make('created_at')->label('When')->dateTime(),
                    TextEntry::make('tenant.name')->label('Client')->placeholder('Global / no client'),
                    TextEntry::make('log_name')->label('Area')->badge()->placeholder('—'),
                    TextEntry::make('event')->label('Action')->badge()->placeholder('—'),
                    TextEntry::make('description')->columnSpanFull(),
                ]),
            Section::make('Actor & record')
                ->columns(2)
                ->schema([
                    TextEntry::make('causer.name')->label('Performed by')->placeholder('System'),
                    TextEntry::make('subject_type')
                        ->label('Record type')
                        ->formatStateUsing(fn (?string $state): string => $state ? class_basename($state) : '—'),
                    TextEntry::make('subject_id')->label('Record id')->placeholder('—'),
                ]),
            Section::make('Changes')
                ->schema([
                    TextEntry::make('attribute_changes')
                        ->hiddenLabel()
                        ->placeholder('No field changes')
                        ->formatStateUsing(fn ($state): ?string => self::asJson($state))
                        ->columnSpanFull(),
                ]),
            Section::make('Extra properties')
                ->collapsed()
                ->schema([
                    TextEntry::make('properties')
                        ->hiddenLabel()
                        ->placeholder('—')
                        ->formatStateUsing(fn ($state): ?string => self::asJson($state))
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivityLogs::route('/'),
            'view' => ViewActivityLog::route('/{record}'),
        ];
    }

    /** The log is append-only — nothing creates a row through the panel. */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Pretty-print the changes / properties JSON for the view page; null (→
     * placeholder) when there is nothing to show.
     */
    private static function asJson(mixed $state): ?string
    {
        $array = $state instanceof Collection ? $state->toArray() : (array) $state;

        return $array === [] ? null : json_encode($array, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
