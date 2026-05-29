<?php

namespace App\Filament\Resources\Tenants\Schemas;

use App\Tenancy\Settings\BusinessHoursForm;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Tenant create / edit form (M3 Checkpoint A + B.5.1).
 *
 * Identity (name, slug) is freely editable. Lifecycle fields (status, the
 * timestamps, the reason) are read-only here — they are written only by
 * Tenant::transitionTo() through the Suspend / Unsuspend / Archive header
 * actions on EditTenant. This keeps "what suspended means" in one place.
 *
 * The Settings sections (Hours / SLAs / Dispositions / Scripts) appear only
 * on Edit (the Create path uses the wizard, not this form). Settings bind to
 * flat top-level form keys; EditTenant's mutate hooks flatten the DTO on fill
 * and rebuild it on save, preserving fields the form doesn't show
 * (portal_access, campaign_template).
 */
class TenantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identity')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('slug')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->helperText('URL-safe id. Lower-case letters, numbers, dashes.'),
                    ])
                    ->columns(2),

                Section::make('Lifecycle')
                    ->description('Status changes happen through the Suspend / Unsuspend / Archive buttons above — never edited directly.')
                    ->schema([
                        Placeholder::make('status_display')
                            ->label('Status')
                            ->content(fn ($record) => $record?->status?->label() ?? '—'),
                        Placeholder::make('suspended_at_display')
                            ->label('Suspended at')
                            ->content(fn ($record) => $record?->suspended_at?->toDayDateTimeString() ?? '—'),
                        Placeholder::make('archived_at_display')
                            ->label('Archived at')
                            ->content(fn ($record) => $record?->archived_at?->toDayDateTimeString() ?? '—'),
                        Placeholder::make('status_reason_display')
                            ->label('Status reason')
                            ->content(fn ($record) => $record?->status_reason ?? '—')
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->hiddenOn('create'),

                Section::make('Business hours')
                    ->description('Per-day operating hours. Toggle a day off to mark it closed.')
                    ->schema(BusinessHoursForm::fields())
                    ->collapsible()
                    ->collapsed()
                    ->hiddenOn('create'),

                Section::make('SLAs')
                    ->description('Service-level defaults. Per-campaign overrides land in M4.')
                    ->schema([
                        TextInput::make('sla.first_attempt_window_min')
                            ->label('First attempt window (minutes)')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                        TextInput::make('sla.max_attempts')
                            ->label('Max attempts per lead')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                        TextInput::make('sla.callback_honour_window_hours')
                            ->label('Callback honour window (hours)')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                    ])
                    ->columns(3)
                    ->collapsible()
                    ->collapsed()
                    ->hiddenOn('create'),

                Section::make('Dispositions')
                    ->description('Outcomes agents can mark on a call. Add, edit, reorder. At least one required.')
                    ->schema([
                        Repeater::make('dispositions')
                            ->label('')
                            ->schema([
                                TextInput::make('code')
                                    ->required()
                                    ->maxLength(40),
                                TextInput::make('label')
                                    ->required()
                                    ->maxLength(60),
                                Toggle::make('is_contact')
                                    ->label('Counts as contact'),
                                Toggle::make('is_sale')
                                    ->label('Counts as sale'),
                            ])
                            ->columns(4)
                            ->reorderable()
                            ->itemLabel(fn (array $state): ?string => $state['label'] ?? $state['code'] ?? null)
                            ->minItems(1),
                    ])
                    ->collapsible()
                    ->collapsed()
                    ->hiddenOn('create'),

                Section::make('Scripts')
                    ->description('Reference text for agents on a call. All optional.')
                    ->schema([
                        Textarea::make('scripts.opening')->label('Opening')->rows(3),
                        Textarea::make('scripts.objection')->label('Objection handling')->rows(3),
                        Textarea::make('scripts.closing')->label('Closing')->rows(3),
                    ])
                    ->collapsible()
                    ->collapsed()
                    ->hiddenOn('create'),
            ]);
    }
}
