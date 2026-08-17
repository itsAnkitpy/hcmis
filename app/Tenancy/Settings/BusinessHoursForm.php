<?php

namespace App\Tenancy\Settings;

use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Fieldset;

/**
 * Shared 7-weekday business-hours form field set, plus form↔settings
 * conversion. Used by the M3 onboarding wizard (CreateTenant) and the M3
 * post-onboarding Edit form.
 *
 * Form payload shape:   ['monday' => ['is_open' => bool, 'open' => 'HH:MM', 'close' => 'HH:MM'], …]
 * Settings hours shape: ['monday' => ['open' => 'HH:MM', 'close' => 'HH:MM'] | null, …]
 *                       (null = closed that day)
 */
class BusinessHoursForm
{
    /**
     * @var array<string, string>
     */
    private const WEEKDAYS = [
        'monday' => 'Monday',
        'tuesday' => 'Tuesday',
        'wednesday' => 'Wednesday',
        'thursday' => 'Thursday',
        'friday' => 'Friday',
        'saturday' => 'Saturday',
        'sunday' => 'Sunday',
    ];

    /**
     * @var array<int, string>
     */
    private const OPEN_BY_DEFAULT = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];

    /**
     * Seven Fieldsets, one per weekday — Open toggle + Opens-at / Closes-at
     * TimePickers (visible only when Open).
     *
     * @return array<int, Fieldset>
     */
    public static function fields(): array
    {
        return collect(self::WEEKDAYS)
            ->map(fn (string $label, string $key): Fieldset => Fieldset::make($label)
                ->schema([
                    Toggle::make("hours.{$key}.is_open")
                        ->label('Open')
                        ->default(in_array($key, self::OPEN_BY_DEFAULT, strict: true))
                        ->live(),
                    // 🔴 THESE TWO ARE NOT INSTANTS, SO THEY ARE NOT CONVERTED (S118).
                    // "Opens at 09:30" is a wall-clock time already written on the
                    // client's own clock, stored as the text `09:30`. S118 set a
                    // panel-wide reading zone for date-AND-time values, which Filament
                    // also applies to a time-only field — it turned 09:30 into 04:00 on
                    // save. Pinning both ends to the application clock makes the
                    // conversion an identity, which is the honest answer for a field
                    // that carries no date to convert from.
                    TimePicker::make("hours.{$key}.open")
                        ->label('Opens at')
                        ->seconds(false)
                        ->timezone(config('app.timezone'))
                        ->default('09:30')
                        ->visible(fn (callable $get) => (bool) $get("hours.{$key}.is_open")),
                    TimePicker::make("hours.{$key}.close")
                        ->label('Closes at')
                        ->seconds(false)
                        ->timezone(config('app.timezone'))
                        ->default('18:30')
                        ->visible(fn (callable $get) => (bool) $get("hours.{$key}.is_open")),
                ])
                ->columns(3))
            ->values()
            ->all();
    }

    /**
     * Form payload → settings hours shape (null for closed days).
     *
     * @param  array<string, array{is_open?: bool, open?: string, close?: string}>  $raw
     * @return array<string, array{open: string, close: string}|null>
     */
    public static function fromForm(array $raw): array
    {
        $out = [];

        foreach (self::WEEKDAYS as $day => $label) {
            $row = $raw[$day] ?? null;

            if (! is_array($row) || ! ($row['is_open'] ?? false)) {
                $out[$day] = null;

                continue;
            }

            $out[$day] = [
                'open' => $row['open'] ?? '09:30',
                'close' => $row['close'] ?? '18:30',
            ];
        }

        return $out;
    }

    /**
     * Settings hours shape → form payload (adds the is_open toggle).
     *
     * @param  array<string, array{open?: string, close?: string}|null>  $hours
     * @return array<string, array{is_open: bool, open: string, close: string}>
     */
    public static function toForm(array $hours): array
    {
        $out = [];

        foreach (self::WEEKDAYS as $day => $label) {
            $row = $hours[$day] ?? null;

            if (! is_array($row)) {
                $out[$day] = ['is_open' => false, 'open' => '09:30', 'close' => '18:30'];

                continue;
            }

            $out[$day] = [
                'is_open' => true,
                'open' => $row['open'] ?? '09:30',
                'close' => $row['close'] ?? '18:30',
            ];
        }

        return $out;
    }
}
