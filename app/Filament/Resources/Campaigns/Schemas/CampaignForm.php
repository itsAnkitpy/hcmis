<?php

namespace App\Filament\Resources\Campaigns\Schemas;

use App\Enums\CampaignCategory;
use App\Enums\CampaignTemplate;
use App\Enums\DialMode;
use App\Filament\Support\CampaignCustomFields;
use Carbon\Carbon;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Campaign create / edit form (M4.C + M4.D). Core fields plus the per-campaign
 * custom-field definitions (FR-LC02) — the lead form renders inputs for these.
 * tenant_id is not a field: it is auto-stamped from the active client context
 * by BelongsToTenant.
 *
 * The Dialing section is DIAL-1 DP-5. Every field there defaults to what the
 * floor already does, so opening this form on an existing campaign and saving
 * it changes nothing.
 */
class CampaignForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Select::make('template')
                    ->options(collect(CampaignTemplate::cases())
                        ->mapWithKeys(fn (CampaignTemplate $t): array => [$t->value => $t->label()])
                        ->all())
                    ->required()
                    ->native(false),
                Toggle::make('is_active')
                    ->default(true)
                    ->helperText('Paused campaigns stay visible but are not worked.'),

                Section::make('Dialing')
                    ->description('How calls on this campaign get started. Leave it on Manual and nothing changes — agents press Dial as they do today.')
                    ->schema([
                        Select::make('dial_mode')
                            ->label('Dial mode')
                            ->options(collect(DialMode::cases())
                                ->mapWithKeys(fn (DialMode $mode): array => [$mode->value => $mode->label()])
                                ->all())
                            ->required()
                            ->native(false)
                            ->default(DialMode::Manual->value)
                            // Two fields below read this one, so the form has to know
                            // the moment it changes rather than at save.
                            ->live(),

                        Toggle::make('is_dialing')
                            ->label('Dialer running')
                            ->default(false)
                            ->helperText('The stop switch. Separate from Active on purpose — a team leader can halt the dialer without taking the campaign off the floor.')
                            ->visible(fn (callable $get): bool => $get('dial_mode') === DialMode::Progressive->value),

                        Select::make('category')
                            ->label('Call category')
                            ->options(collect(CampaignCategory::cases())
                                ->mapWithKeys(fn (CampaignCategory $category): array => [$category->value => $category->label()])
                                ->all())
                            ->required()
                            ->native(false)
                            ->default(CampaignCategory::Promotional->value)
                            ->helperText('Marketing calls are barred outside 10:00–21:00 by TRAI. Support and transactional calls are not.')
                            ->live(),

                        TextInput::make('caller_id')
                            ->label('Caller ID')
                            ->maxLength(20)
                            ->placeholder('Uses the system number')
                            // DP-6a / G3. You cannot auto-dial without declaring what you
                            // are calling from: one configured system number cannot serve
                            // both a 140 marketing campaign and a 1601 support one.
                            ->required(fn (callable $get): bool => $get('dial_mode') === DialMode::Progressive->value)
                            ->validationMessages([
                                'required' => 'A progressive campaign needs its own caller ID — marketing must call from a 140-series number, support from a 1601-series one.',
                            ])
                            // No format check on purpose (DQ-5): TRAI adds number series by
                            // notification, so a hardcoded 140/1601 prefix rule would one day
                            // refuse a legitimate number.
                            ->helperText('The number the customer sees. Marketing needs a 140-series number, support a 1601-series one. Blank uses the system default.'),

                        // 🔴 NOT converted, and that is the point (S118). A calling window is
                        // wall-clock time with no date attached, so pinning both ends to the
                        // application clock makes the conversion an identity. Without it the
                        // panel's reading zone turns 10:00 into 04:30 on save.
                        TimePicker::make('dial_start_time')
                            ->label('Start calling at')
                            ->seconds(false)
                            ->timezone(config('app.timezone'))
                            // Pre-filled with the legal band so a new campaign is compliant
                            // before anyone touches it — the same reason the column has a
                            // default rather than being nullable (F2).
                            ->default('10:00')
                            ->required()
                            // DP-6a / G2. Only promotional campaigns are clamped; a service
                            // campaign is left open, which is the whole reason `category`
                            // earns a column.
                            ->rule(fn (callable $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                if ($get('category') !== CampaignCategory::Promotional->value) {
                                    return;
                                }

                                if (self::minutes($value) < self::minutes(CampaignCategory::PROMOTIONAL_START)) {
                                    $fail('Marketing calls cannot start before '.CampaignCategory::PROMOTIONAL_START.'. Set the category to Service if these are support or transactional calls.');
                                }
                            }),

                        TimePicker::make('dial_end_time')
                            ->label('Stop calling at')
                            ->seconds(false)
                            ->timezone(config('app.timezone'))
                            ->default('21:00')
                            ->required()
                            ->rule(fn (callable $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                // F5 first: a window that ends before it starts saves fine and
                                // then silently never dials, because slice 3 asks whether now
                                // is between the two. Refusing it here is one line; supporting
                                // a window that wraps past midnight is a night-shift feature
                                // nobody has asked for.
                                // ponytail: no wrap-around windows. If a client outside India
                                // ever needs one, drop this check and make slice 3's between
                                // an either/or — per-destination hours are a separate module.
                                if (self::minutes($value) <= self::minutes($get('dial_start_time'))) {
                                    $fail('The stop time must be after the start time.');

                                    return;
                                }

                                if ($get('category') !== CampaignCategory::Promotional->value) {
                                    return;
                                }

                                if (self::minutes($value) > self::minutes(CampaignCategory::PROMOTIONAL_END)) {
                                    $fail('Marketing calls cannot run past '.CampaignCategory::PROMOTIONAL_END.'. Set the category to Service if these are support or transactional calls.');
                                }
                            }),

                        TextInput::make('max_attempts')
                            ->label('Give up after')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(50)
                            ->placeholder('No limit')
                            ->suffix('attempts')
                            // DP-14 / G3, the caller_id pattern again. The retry gap alone
                            // still permits ~5 calls a day, every day, forever; the cap is
                            // what ends it. Optional stays fine on a manual campaign, where
                            // a person decides each dial.
                            ->required(fn (callable $get): bool => $get('dial_mode') === DialMode::Progressive->value)
                            ->validationMessages([
                                'required' => 'An auto-dialing campaign has to give up eventually — set how many attempts a number gets before it is left alone.',
                            ])
                            ->helperText('Stop serving a number that never answers. Required once the dialer is doing the calling.'),

                        TextInput::make('retry_gap_minutes')
                            ->label('Wait between calls')
                            // Only the dialer obeys this, so only a dialing campaign should
                            // be shown it — the `is_dialing` rule two fields up. Left visible
                            // it reads as a promise on a manual campaign, where an agent
                            // decides each dial and nothing spaces them out. Hidden, the
                            // column's own default fills it in and nothing is lost.
                            ->visible(fn (callable $get): bool => $get('dial_mode') === DialMode::Progressive->value)
                            ->numeric()
                            ->required()
                            // 🔴 The floor is the guard, not decoration: without it a client
                            // types 1 and rebuilds the exact bug DP-14 exists to fix. We
                            // cannot tell a busy signal from a rang-out (`calls.outcome` has
                            // no busy), so short gaps are the wrong end of our own blindness.
                            // 15 and 1440 are judgements — no regulation sets a gap at all.
                            ->minValue(15)
                            ->maxValue(1440)
                            ->default(120)
                            ->suffix('minutes')
                            ->validationMessages([
                                'min' => 'Anything under 15 minutes rings the same person over and over — that is what this setting exists to prevent.',
                            ])
                            ->helperText('How long the dialer leaves a number alone before trying it again. Two hours by default.'),
                    ])
                    // The resource form is a 2-column grid, so without this the whole
                    // section is squeezed into one half-width slot next to Template.
                    ->columnSpanFull()
                    ->columns(3),

                CampaignCustomFields::definitionRepeater()
                    ->columnSpanFull(),
            ]);
    }

    /**
     * A wall-clock time as minutes past midnight, so two of them can be compared
     * without caring whether the picker handed back `08:00`, `08:00:00` or a full
     * datetime. Deliberately NOT a timezone conversion — see the S118 note above.
     */
    private static function minutes(mixed $time): int
    {
        $parsed = Carbon::parse((string) $time);

        return $parsed->hour * 60 + $parsed->minute;
    }
}
