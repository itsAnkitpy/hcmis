<?php

namespace App\Filament\Resources\Menus\Pages;

use App\Filament\Resources\Menus\MenuResource;
use App\Filament\Resources\Menus\Pages\Concerns\HandlesMenuUploads;
use Filament\Resources\Pages\CreateRecord;

class CreateMenu extends CreateRecord
{
    use HandlesMenuUploads;

    protected static string $resource = MenuResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->liftMenuUploads($data);
    }

    protected function afterCreate(): void
    {
        $this->convertMenuUploads();
    }
}
