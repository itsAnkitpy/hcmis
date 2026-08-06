<?php

namespace App\Filament\Resources\PhoneNumbers\Pages;

use App\Filament\Resources\PhoneNumbers\PhoneNumberResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPhoneNumbers extends ListRecords
{
    protected static string $resource = PhoneNumberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
