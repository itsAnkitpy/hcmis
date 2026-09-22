<?php

namespace App\Filament\Resources\Menus;

use App\Filament\Resources\Menus\Pages\CreateMenu;
use App\Filament\Resources\Menus\Pages\EditMenu;
use App\Filament\Resources\Menus\Pages\ListMenus;
use App\Filament\Resources\Menus\Schemas\MenuForm;
use App\Filament\Resources\Menus\Tables\MenusTable;
use App\Models\Menu;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The spoken menu a caller meets before any desk rings (inbound-audio slice 6).
 *
 * HEAD OFFICE ONLY (AU-19), which MenuPolicy enforces — deliberately stricter than the
 * phone-number screen beside it, because a wrong menu traps every caller on that number
 * rather than mislabelling a report.
 *
 * Built here, pointed at from a number (AU-18): one client may run a sales menu on one
 * number and a support menu on another, and neither is built twice.
 *
 * 🔴 NO "NEEDS A CURRENT CLIENT" GUARD, unlike every per-client screen beside it, and
 * that is not an oversight. SetCurrentTenant never pins a global user to one client —
 * head office always browses in the cross-client posture with no client selected — so
 * requiring one here would lock out the only people AU-19 lets in. The form asks which
 * client instead, and the table carries the Client column for the same reason.
 */
class MenuResource extends Resource
{
    protected static ?string $model = Menu::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Call Menus';

    protected static ?string $modelLabel = 'call menu';

    public static function form(Schema $schema): Schema
    {
        return MenuForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MenusTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMenus::route('/'),
            'create' => CreateMenu::route('/create'),
            'edit' => EditMenu::route('/{record}/edit'),
        ];
    }
}
