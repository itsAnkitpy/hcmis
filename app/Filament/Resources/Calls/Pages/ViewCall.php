<?php

namespace App\Filament\Resources\Calls\Pages;

use App\Filament\Resources\Calls\CallResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewCall extends ViewRecord
{
    protected static string $resource = CallResource::class;

    /**
     * Download the recording via the gated stream route (CR-2), shown only when one
     * exists. Play is no longer a header action (HD-1): the embedded AudioPlayerEntry
     * in the infolist plays it in-page, so the new-tab Play link is redundant.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('download')
                ->icon(Heroicon::ArrowDownTray)
                ->color('gray')
                ->url(fn (): string => route('calls.recording', ['record' => $this->getRecord(), 'download' => 1]))
                ->visible(fn (): bool => filled($this->getRecord()->recording_path)),
        ];
    }
}
