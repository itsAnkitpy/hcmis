<?php

namespace App\Filament\Resources\BreakCategories;

use App\Filament\Resources\BreakCategories\Pages\CreateBreakCategory;
use App\Filament\Resources\BreakCategories\Pages\EditBreakCategory;
use App\Filament\Resources\BreakCategories\Pages\ListBreakCategories;
use App\Filament\Resources\BreakCategories\Schemas\BreakCategoryForm;
use App\Filament\Resources\BreakCategories\Tables\BreakCategoriesTable;
use App\Models\BreakCategory;
use App\Tenancy\TenantContext;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class BreakCategoryResource extends Resource
{
    protected static ?string $model = BreakCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPauseCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return BreakCategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BreakCategoriesTable::configure($table);
    }

    /** Creating needs a current client — see CampaignResource::canCreate(). */
    public static function canCreate(): bool
    {
        return TenantContext::has() && parent::canCreate();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBreakCategories::route('/'),
            'create' => CreateBreakCategory::route('/create'),
            'edit' => EditBreakCategory::route('/{record}/edit'),
        ];
    }
}
