<?php

declare(strict_types=1);

namespace App\Filament\Resources\Menus\Pages\Concerns;

use App\Enums\TenantMedia;
use App\Jobs\ConvertTenantMediaJob;

/**
 * Lift a menu's uploads out of the form data before the write and hand them to the
 * queue after it (inbound-audio slice 6, the EditTenant pattern).
 *
 * An upload field is not a column. The conversion job also needs a SAVED menu row to
 * write its path onto, so dispatching before the save would race it.
 *
 * 🔴 EACH KEY'S UPLOAD IS REMEMBERED AGAINST ITS KEY, NOT ITS POSITION. Several keys can
 * be uploaded in one save, each becomes its own queued job, and by the time a job runs
 * the list may have been reordered — a job that wrote by position would put its file on
 * whichever key had moved into that slot.
 */
trait HandlesMenuUploads
{
    /** The greeting's just-uploaded file, or null when this save touched it. */
    private ?string $uploadedGreeting = null;

    /**
     * Each key's just-uploaded file, by the key it belongs to.
     *
     * @var array<string, string>
     */
    private array $uploadedOptionSounds = [];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function liftMenuUploads(array $data): array
    {
        $this->uploadedGreeting = filled($data['greeting_upload'] ?? null)
            ? (string) $data['greeting_upload']
            : null;

        unset($data['greeting_upload']);

        $this->uploadedOptionSounds = [];
        $options = [];

        foreach ($data['options'] ?? [] as $option) {
            $key = (string) ($option['key'] ?? '');

            if (filled($option['sound_upload'] ?? null) && $key !== '') {
                $this->uploadedOptionSounds[$key] = (string) $option['sound_upload'];
            }

            unset($option['sound_upload']);

            $option['sound_path'] = $option['sound_path'] ?? null;
            $option['sound_rights_confirmed'] = (bool) ($option['sound_rights_confirmed'] ?? false);

            $options[] = $option;
        }

        $data['options'] = array_values($options);

        return $data;
    }

    /**
     * Queued, not inline: converting audio in the request would hold the page open and
     * nobody is waiting on it — the menu simply keeps its previous sound until it lands.
     */
    protected function convertMenuUploads(): void
    {
        $disk = (string) config('telephony.media.disk');
        $menu = $this->record;

        if ($this->uploadedGreeting !== null) {
            ConvertTenantMediaJob::dispatch(
                $menu->tenant,
                TenantMedia::MenuGreeting,
                $disk,
                $this->uploadedGreeting,
                $menu,
            );
        }

        foreach ($this->uploadedOptionSounds as $key => $uploadPath) {
            ConvertTenantMediaJob::dispatch(
                $menu->tenant,
                TenantMedia::MenuOption,
                $disk,
                $uploadPath,
                $menu,
                (string) $key,
            );
        }

        $this->uploadedGreeting = null;
        $this->uploadedOptionSounds = [];
    }
}
