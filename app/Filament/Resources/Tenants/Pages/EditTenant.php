<?php

namespace App\Filament\Resources\Tenants\Pages;

use App\Enums\TenantMedia;
use App\Enums\TenantStatus;
use App\Filament\Resources\Tenants\TenantResource;
use App\Jobs\ConvertTenantMediaJob;
use App\Models\Tenant;
use App\Tenancy\Settings\BusinessHoursForm;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

/**
 * Lifecycle actions on a tenant. Every header action routes through
 * Tenant::transitionTo() — the EditTenant page never writes `status` or the
 * lifecycle timestamps directly. The form's Lifecycle section is read-only
 * for the same reason.
 *
 * Delete is intentionally absent — archive is the supported removal path
 * (M3 D-M3-4: status flag, no soft-delete).
 */
class EditTenant extends EditRecord
{
    protected static string $resource = TenantResource::class;

    /**
     * The just-uploaded sound files, by kind, carried from save to afterSave
     * (inbound-audio slices 3 and 4). They are not columns on the client, so they are
     * lifted out of the form data before the write and acted on after it — the
     * conversion job needs a saved client row to update, and dispatching before the
     * save would race it.
     *
     * @var array<string, string>
     */
    private array $uploadedMedia = [];

    /**
     * Flatten the TenantSettings DTO into top-level form keys (hours, sla,
     * dispositions, scripts) so the Edit form's Settings sections can bind
     * to them. Fields the form doesn't show (portal_access, campaign_template)
     * are preserved on save via mutateFormDataBeforeSave.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $settings = $this->record->settings->toArray();

        $data['hours'] = BusinessHoursForm::toForm($settings['hours'] ?? []);
        $data['sla'] = $settings['sla'] ?? [];
        $data['dispositions'] = $settings['dispositions'] ?? [];
        $data['scripts'] = $settings['scripts'] ?? [];
        $data['closed_hours'] = $settings['closed_hours'];
        $data['holidays'] = $settings['holidays'];

        return $data;
    }

    /**
     * Reassemble the settings payload from the form's flat keys, preserving
     * portal_access + campaign_template (set at onboarding, not editable here).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $current = $this->record->settings->toArray();

        $settings = $current;
        $settings['hours'] = BusinessHoursForm::fromForm($data['hours'] ?? []);
        $settings['sla'] = $data['sla'] ?? ($current['sla'] ?? []);
        $settings['dispositions'] = $data['dispositions'] ?? [];
        $settings['scripts'] = array_filter(
            $data['scripts'] ?? [],
            fn ($v) => $v !== null && $v !== ''
        );
        $settings['closed_hours'] = $data['closed_hours'] ?? ($current['closed_hours'] ?? null);
        $settings['holidays'] = collect($data['holidays'] ?? [])->filter()->unique()->sort()->values()->all();

        $data['settings'] = $settings;

        // inbound-audio slices 3 and 4: an upload field is not a column. The rights tick
        // box beside it IS one, so it saves — and is audited — with everything else here.
        $this->uploadedMedia = [];

        foreach (TenantMedia::cases() as $kind) {
            if (filled($data[$kind->uploadField()] ?? null)) {
                $this->uploadedMedia[$kind->value] = (string) $data[$kind->uploadField()];
            }

            unset($data[$kind->uploadField()]);
        }

        unset($data['hours'], $data['sla'], $data['dispositions'], $data['scripts'], $data['closed_hours'], $data['holidays']);

        return $data;
    }

    /**
     * Hand each fresh upload to the queue (inbound-audio slice 3 step 2, slice 4).
     * Queued, not inline: converting audio in the request would hold the page open, and
     * nobody is waiting on it — the client keeps hearing their previous file, or the
     * default, until it lands.
     */
    protected function afterSave(): void
    {
        foreach ($this->uploadedMedia as $kind => $uploadPath) {
            ConvertTenantMediaJob::dispatch(
                $this->record,
                TenantMedia::from($kind),
                (string) config('telephony.media.disk'),
                $uploadPath,
            );
        }

        $this->uploadedMedia = [];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('suspend')
                ->label('Suspend')
                ->color('warning')
                ->icon(Heroicon::OutlinedPauseCircle)
                ->visible(fn (Tenant $record): bool => $record->status === TenantStatus::Active)
                ->form([
                    Textarea::make('reason')
                        ->label('Reason')
                        ->required()
                        ->rows(3)
                        ->helperText('Captured on the tenant record. Why is this client being suspended?'),
                ])
                ->action(function (Tenant $record, array $data): void {
                    $record->transitionTo(TenantStatus::Suspended, $data['reason']);

                    $this->refreshFormData(['status', 'suspended_at', 'archived_at', 'status_reason']);
                }),

            Action::make('unsuspend')
                ->label('Unsuspend')
                ->color('success')
                ->icon(Heroicon::OutlinedPlayCircle)
                ->visible(fn (Tenant $record): bool => $record->status === TenantStatus::Suspended)
                ->requiresConfirmation()
                ->action(function (Tenant $record): void {
                    $record->transitionTo(TenantStatus::Active);

                    $this->refreshFormData(['status', 'suspended_at', 'archived_at', 'status_reason']);
                }),

            Action::make('archive')
                ->label('Archive')
                ->color('danger')
                ->icon(Heroicon::OutlinedArchiveBoxXMark)
                ->visible(fn (Tenant $record): bool => $record->status !== TenantStatus::Archived)
                ->requiresConfirmation()
                ->modalDescription('Archiving is permanent — an archived client cannot be reactivated. Use Suspend if this is temporary.')
                ->form([
                    Textarea::make('reason')
                        ->label('Reason')
                        ->required()
                        ->rows(3),
                ])
                ->action(function (Tenant $record, array $data): void {
                    $record->transitionTo(TenantStatus::Archived, $data['reason']);

                    $this->refreshFormData(['status', 'suspended_at', 'archived_at', 'status_reason']);
                }),
        ];
    }
}
