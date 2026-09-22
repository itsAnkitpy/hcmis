<?php

namespace App\Filament\Resources\Menus\Pages;

use App\Filament\Resources\Menus\MenuResource;
use App\Filament\Resources\Menus\Pages\Concerns\HandlesMenuUploads;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMenu extends EditRecord
{
    use HandlesMenuUploads;

    protected static string $resource = MenuResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->liftMenuUploads($data);
    }

    protected function afterSave(): void
    {
        $this->convertMenuUploads();
    }

    protected function getHeaderActions(): array
    {
        return [
            // Deleting also takes this menu's sounds off the disk (Menu::booted), which
            // is the only way one is revoked now that the media route no longer asks who
            // owns a file. Numbers pointing here fall back to today's behaviour.
            DeleteAction::make()
                ->modalDescription('Callers on any number using this menu will go straight to an agent, as they did before the menu existed. Its recordings are deleted.'),
        ];
    }
}
