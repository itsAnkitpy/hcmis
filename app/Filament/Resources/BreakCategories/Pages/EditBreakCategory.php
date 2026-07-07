<?php

namespace App\Filament\Resources\BreakCategories\Pages;

use App\Filament\Resources\BreakCategories\BreakCategoryResource;
use Filament\Resources\Pages\EditRecord;

/**
 * Deliberately no DeleteAction (BK-1): deactivate via the Active toggle
 * instead, so history rows keep resolving to a real category.
 */
class EditBreakCategory extends EditRecord
{
    protected static string $resource = BreakCategoryResource::class;
}
