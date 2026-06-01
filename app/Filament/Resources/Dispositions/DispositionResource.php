<?php

namespace App\Filament\Resources\Dispositions;

use App\Filament\Resources\Dispositions\Pages\CreateDisposition;
use App\Filament\Resources\Dispositions\Pages\EditDisposition;
use App\Filament\Resources\Dispositions\Pages\ListDispositions;
use App\Filament\Resources\Dispositions\Schemas\DispositionForm;
use App\Filament\Resources\Dispositions\Tables\DispositionsTable;
use App\Models\Disposition;
use App\Tenancy\TenantContext;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class DispositionResource extends Resource
{
    protected static ?string $model = Disposition::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return DispositionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DispositionsTable::configure($table);
    }

    /** Creating needs a current client — see CampaignResource::canCreate(). */
    public static function canCreate(): bool
    {
        return TenantContext::has() && parent::canCreate();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDispositions::route('/'),
            'create' => CreateDisposition::route('/create'),
            'edit' => EditDisposition::route('/{record}/edit'),
        ];
    }
}
