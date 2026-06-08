<?php

namespace App\Filament\Resources\DncEntries\Pages;

use App\Filament\Resources\DncEntries\DncEntryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDncEntry extends CreateRecord
{
    protected static string $resource = DncEntryResource::class;
}
