<?php

namespace App\Tenancy\Settings;

use App\Enums\CampaignTemplate;
use Livewire\Wireable;

/**
 * Typed view of a tenant's non-voice settings (M3 §5.2). Stored as JSONB on
 * tenants.settings and round-tripped via TenantSettingsCast.
 *
 * Implements Livewire\Wireable so Filament pages that hydrate a Tenant model
 * across requests can dehydrate this DTO to an array on the wire and rebuild
 * it on the next click. Without this, EditTenant blows up the moment Livewire
 * tries to serialize the page's $record.
 *
 * Nested shapes (hours, sla, dispositions, scripts) are kept as arrays at
 * Checkpoint A; the wizard work in Checkpoint B will split them into their own
 * value objects as needed.
 *
 * @phpstan-type HoursShape array<string, array{open: string, close: string}|null>
 * @phpstan-type SlaShape array{first_attempt_window_min: int, max_attempts: int, callback_honour_window_hours: int}
 * @phpstan-type DispositionShape array{code: string, label: string, is_contact: bool, is_sale: bool}
 * @phpstan-type ScriptsShape array<string, string>
 */
class TenantSettings implements Wireable
{
    /**
     * @param  HoursShape  $hours  weekday => {open, close}|null (null = closed that day)
     * @param  SlaShape  $sla
     * @param  array<int, DispositionShape>  $dispositions
     * @param  ScriptsShape  $scripts  call type (opening|objection|closing) => script text
     */
    public function __construct(
        public array $hours = [],
        public array $sla = [
            'first_attempt_window_min' => 60,
            'max_attempts' => 5,
            'callback_honour_window_hours' => 24,
        ],
        public array $dispositions = [],
        public array $scripts = [],
        public bool $portalAccess = false,
        public ?CampaignTemplate $campaignTemplate = null,
    ) {}

    /**
     * Build from the JSONB payload (already array-decoded by Eloquent / the cast).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            hours: $data['hours'] ?? [],
            sla: array_merge(
                [
                    'first_attempt_window_min' => 60,
                    'max_attempts' => 5,
                    'callback_honour_window_hours' => 24,
                ],
                $data['sla'] ?? []
            ),
            dispositions: $data['dispositions'] ?? [],
            scripts: $data['scripts'] ?? [],
            portalAccess: (bool) ($data['portal_access'] ?? false),
            campaignTemplate: isset($data['campaign_template']) ? CampaignTemplate::tryFrom((string) $data['campaign_template']) : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'hours' => $this->hours,
            'sla' => $this->sla,
            'dispositions' => $this->dispositions,
            'scripts' => $this->scripts,
            'portal_access' => $this->portalAccess,
            'campaign_template' => $this->campaignTemplate?->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toLivewire(): array
    {
        return $this->toArray();
    }

    /**
     * @param  array<string, mixed>|mixed  $value
     */
    public static function fromLivewire($value): self
    {
        return self::fromArray(is_array($value) ? $value : []);
    }
}
