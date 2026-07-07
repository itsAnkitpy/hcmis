<?php

namespace App\Filament\Resources\BreakCategories\Pages;

use App\Filament\Resources\BreakCategories\BreakCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBreakCategories extends ListRecords
{
    protected static string $resource = BreakCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
