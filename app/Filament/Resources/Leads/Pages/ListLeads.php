<?php

namespace App\Filament\Resources\Leads\Pages;

use App\Filament\Resources\Leads\LeadResource;
use App\Jobs\ImportLeadsJob;
use App\Models\Campaign;
use App\Models\Lead;
use App\Tenancy\TenantContext;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListLeads extends ListRecords
{
    protected static string $resource = LeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->importLeadsAction(),
            $this->downloadTemplateAction(),
            CreateAction::make(),
        ];
    }

    /**
     * Bulk lead upload (M5, FR-LC01). Visible only to users who may create leads
     * (D-M4-5: global staff + team_leader) AND who have a current client — global
     * staff in all-clients mode have no client to import into, so it is hidden for
     * them, matching the rest of the M4 create posture.
     *
     * The heavy lifting (tenant binding, validation, dedupe) happens in the queued
     * ImportLeadsJob; this surface only captures the file + target campaign and
     * hands off two scalars.
     */
    private function importLeadsAction(): Action
    {
        return Action::make('importLeads')
            ->label('Import leads')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->visible(fn (): bool => $this->canImport())
            ->modalHeading('Import leads from a file')
            ->modalDescription('Upload a CSV or XLSX file. Each row becomes a lead in the chosen campaign. Duplicate phone numbers and invalid rows are skipped and reported when the import finishes.')
            ->modalSubmitActionLabel('Start import')
            ->schema([
                Select::make('campaign_id')
                    ->label('Target campaign')
                    ->options(fn (): array => Campaign::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->required()
                    ->searchable()
                    ->native(false),
                FileUpload::make('file')
                    ->label('Lead file (CSV or XLSX)')
                    ->required()
                    ->disk('local')
                    // A random per-upload sub-folder avoids name clashes while
                    // keeping the user's original filename for the report.
                    ->directory(fn (): string => 'imports/'.TenantContext::id().'/'.Str::random(8))
                    ->visibility('private')
                    ->getUploadedFileNameForStorageUsing(fn (TemporaryUploadedFile $file): string => $file->getClientOriginalName())
                    // Reject any submitted path that is not a fresh upload — closes
                    // the tamper hole where a crafted request points the import at
                    // another client's file on the shared local disk.
                    ->preventFilePathTampering()
                    ->acceptedFileTypes([
                        'text/csv',
                        'text/plain',
                        'application/csv',
                        'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ])
                    ->maxSize(10240) // 10 MB — within Livewire's default temp-upload ceiling
                    ->helperText('Required column: phone. Optional: name, email, region, plus this campaign\'s custom fields. Download the template for the exact headers.'),
            ])
            ->action(function (array $data): void {
                // Defensive: visible() already gates this, but never dispatch a
                // cross-client or unauthenticated import.
                if (! $this->canImport()) {
                    return;
                }

                $path = $data['file'];

                ImportLeadsJob::dispatch(
                    tenantId: TenantContext::id(),
                    campaignId: (int) $data['campaign_id'],
                    uploaderId: auth()->id(),
                    storedPath: $path,
                    originalName: basename($path),
                );

                Notification::make()
                    ->title('Import started')
                    ->body('Your file is being processed. You will get a notification here when it finishes.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Download a CSV template for the chosen campaign (D-M5-5): the fixed lead
     * columns plus that campaign's custom-field keys, so the uploader knows the
     * exact headers the import expects.
     */
    private function downloadTemplateAction(): Action
    {
        return Action::make('downloadTemplate')
            ->label('Download template')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->visible(fn (): bool => $this->canImport())
            ->modalHeading('Download an import template')
            ->modalSubmitActionLabel('Download')
            ->schema([
                Select::make('campaign_id')
                    ->label('Template for campaign')
                    ->options(fn (): array => Campaign::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->required()
                    ->searchable()
                    ->native(false),
            ])
            ->action(fn (array $data): StreamedResponse => $this->streamTemplate((int) $data['campaign_id']));
    }

    /**
     * Stream the header-only CSV template for a campaign. The campaign is resolved
     * through the tenant wall, so a cross-client id yields the fixed columns only.
     */
    private function streamTemplate(int $campaignId): StreamedResponse
    {
        $campaign = Campaign::find($campaignId);

        $headers = ['phone', 'name', 'email', 'region'];

        foreach ($campaign?->custom_fields ?? [] as $field) {
            if (filled($field['key'] ?? null)) {
                $headers[] = $field['key'];
            }
        }

        return response()->streamDownload(function () use ($headers): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $headers);
            fclose($handle);
        }, 'lead-import-template.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * A current client is set AND the user may create leads (D-M4-5).
     */
    private function canImport(): bool
    {
        return TenantContext::has()
            && (auth()->user()?->can('create', Lead::class) ?? false);
    }
}
