<?php

namespace App\Filament\Resources\Calls\Pages;

use App\Filament\Resources\Calls\CallResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewCall extends ViewRecord
{
    protected static string $resource = CallResource::class;

    /** Play / Download the recording via the gated stream route (CR-2), shown only when one exists. */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('play')
                ->icon(Heroicon::Play)
                ->url(fn (): string => route('calls.recording', $this->getRecord()))
                ->openUrlInNewTab()
                ->visible(fn (): bool => filled($this->getRecord()->recording_path)),
            Action::make('download')
                ->icon(Heroicon::ArrowDownTray)
                ->color('gray')
                ->url(fn (): string => route('calls.recording', ['record' => $this->getRecord(), 'download' => 1]))
                ->visible(fn (): bool => filled($this->getRecord()->recording_path)),
        ];
    }
}
