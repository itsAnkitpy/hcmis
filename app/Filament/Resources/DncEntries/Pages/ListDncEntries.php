<?php

namespace App\Filament\Resources\DncEntries\Pages;

use App\Filament\Resources\DncEntries\DncEntryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDncEntries extends ListRecords
{
    protected static string $resource = DncEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
