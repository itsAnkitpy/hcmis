<?php

namespace App\Filament\Resources\DncEntries;

use App\Filament\Resources\DncEntries\Pages\CreateDncEntry;
use App\Filament\Resources\DncEntries\Pages\EditDncEntry;
use App\Filament\Resources\DncEntries\Pages\ListDncEntries;
use App\Filament\Resources\DncEntries\Schemas\DncEntryForm;
use App\Filament\Resources\DncEntries\Tables\DncEntriesTable;
use App\Models\DncEntry;
use App\Tenancy\TenantContext;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class DncEntryResource extends Resource
{
    protected static ?string $model = DncEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoneXMark;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'DNC list';

    protected static ?string $modelLabel = 'DNC entry';

    protected static ?string $pluralModelLabel = 'DNC entries';

    public static function form(Schema $schema): Schema
    {
        return DncEntryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DncEntriesTable::configure($table);
    }

    /** Creating needs a current client — see DispositionResource::canCreate(). */
    public static function canCreate(): bool
    {
        return TenantContext::has() && parent::canCreate();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDncEntries::route('/'),
            'create' => CreateDncEntry::route('/create'),
            'edit' => EditDncEntry::route('/{record}/edit'),
        ];
    }
}
