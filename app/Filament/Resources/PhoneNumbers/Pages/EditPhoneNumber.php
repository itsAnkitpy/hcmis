<?php

namespace App\Filament\Resources\PhoneNumbers\Pages;

use App\Filament\Resources\PhoneNumbers\PhoneNumberResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPhoneNumber extends EditRecord
{
    protected static string $resource = PhoneNumberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
