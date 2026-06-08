<?php

namespace App\Filament\Resources\DncEntries\Pages;

use App\Filament\Resources\DncEntries\DncEntryResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDncEntry extends EditRecord
{
    protected static string $resource = DncEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
