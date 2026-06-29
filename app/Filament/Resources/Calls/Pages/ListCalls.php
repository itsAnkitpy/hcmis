<?php

namespace App\Filament\Resources\Calls\Pages;

use App\Filament\Resources\Calls\CallResource;
use Filament\Resources\Pages\ListRecords;

class ListCalls extends ListRecords
{
    protected static string $resource = CallResource::class;

    /** No create action — a call record is written by the wrap-up (B3), never here. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
