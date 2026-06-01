<?php

namespace App\Filament\Resources\Dispositions\Pages;

use App\Filament\Resources\Dispositions\DispositionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDispositions extends ListRecords
{
    protected static string $resource = DispositionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
