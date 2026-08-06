<?php

namespace App\Filament\Resources\PhoneNumbers;

use App\Filament\Resources\PhoneNumbers\Pages\CreatePhoneNumber;
use App\Filament\Resources\PhoneNumbers\Pages\EditPhoneNumber;
use App\Filament\Resources\PhoneNumbers\Pages\ListPhoneNumbers;
use App\Filament\Resources\PhoneNumbers\Schemas\PhoneNumberForm;
use App\Filament\Resources\PhoneNumbers\Tables\PhoneNumbersTable;
use App\Models\PhoneNumber;
use App\Tenancy\TenantContext;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The number -> client screen (B2.3a ND-5). The BPO team lead already adds numbers
 * and assigns them to campaigns in their current system, so this is THEIRS: the
 * shared OperationalDataPolicy puts it behind Team Leader / Ops Manager rather than
 * admin-only, and assigning a campaign is the normal path on the form.
 *
 * The screen IS the deliverable. Without it the list exists but is reachable only
 * through the database, which puts back the per-client manual server ritual that
 * ND-1 exists to delete.
 */
class PhoneNumberResource extends Resource
{
    protected static ?string $model = PhoneNumber::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoneArrowDownLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'number';

    public static function form(Schema $schema): Schema
    {
        return PhoneNumberForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PhoneNumbersTable::configure($table);
    }

    /** Creating needs a current client — see CampaignResource::canCreate(). */
    public static function canCreate(): bool
    {
        return TenantContext::has() && parent::canCreate();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPhoneNumbers::route('/'),
            'create' => CreatePhoneNumber::route('/create'),
            'edit' => EditPhoneNumber::route('/{record}/edit'),
        ];
    }
}
