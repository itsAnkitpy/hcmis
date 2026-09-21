<?php

namespace App\Filament\Resources\Tenants\Schemas;

use App\Enums\ClosedHours;
use App\Enums\TenantMedia;
use App\Models\Tenant;
use App\Tenancy\Settings\BusinessHoursForm;
use Closure;
use DateTimeZone;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
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

                // B2.3b-i QD-7 — real columns, not settings JSON: these are operational
                // knobs worth having in the audit trail, which `settings` is excluded
                // from. Blank means "use the system default" (config/telephony.php).
                Section::make('Waiting room')
                    ->description('What happens to a caller when nobody is free. Leave blank to use the system defaults.')
                    ->schema([
                        TextInput::make('ring_seconds')
                            ->label('Ring one agent for (seconds)')
                            ->numeric()
                            ->minValue(5)
                            ->maxValue(120)
                            ->helperText('How long one agent\'s phone rings before the caller goes back to waiting and someone else is tried. Default 20.'),
                        TextInput::make('max_hold_seconds')
                            ->label('Maximum hold (seconds)')
                            ->numeric()
                            ->minValue(30)
                            ->maxValue(3600)
                            ->helperText('How long a caller may wait before we end the call and add them to the missed-call list. Default 180 (three minutes).'),
                    ])
                    ->columns(2)
                    ->hiddenOn('create'),

                // inbound-audio slices 3 and 4 (AU-9, AU-11, AU-13, AU-14). Head office
                // only, which TenantPolicy::update already enforces — editing a client is
                // global staff, so AU-8 needs no new check here. One builder, one section
                // per sound: slices 5 and 8 add a case, not another fifty lines.
                TenantMediaSection::make(TenantMedia::HoldMusic),

                TenantMediaSection::make(TenantMedia::ClosedMessage),

                TenantMediaSection::make(TenantMedia::WaitingMessage),

                // call-export.md CE-10. Same reasoning as the waiting-room settings
                // above: a real column, blank means the system default, and the change
                // reaches the audit trail — because changing this moves calls between
                // days in every report from that moment on.
                Section::make('Reporting')
                    ->description('How this client\'s reports and exported files read. Leave blank to use the system default.')
                    ->schema([
                        Select::make('timezone')
                            ->label('Report time zone')
                            ->options(array_combine(
                                DateTimeZone::listIdentifiers(),
                                DateTimeZone::listIdentifiers(),
                            ))
                            ->searchable()
                            ->native(false)
                            ->placeholder('System default ('.config('app.report_timezone').')')
                            // 🔴 S118 made the second sentence true. The panel showed UTC
                            // until then, and this field said so.
                            ->helperText('Every time this client reads — on screen and in an exported file — is written in this zone, and a day starts and ends in it. Business hours above are not affected: they are already written on this clock.'),
                    ])
                    ->hiddenOn('create'),

                Section::make('Business hours')
                    ->description('Per-day operating hours, on the client\'s own clock. Toggle a day off to mark it closed. A closing time earlier than the opening time runs into the next morning; the same time for both means open 24 hours.')
                    ->schema([
                        // inbound-audio slice 1 (AU-1): off by default, because most saved
                        // hours are the onboarding form's untouched defaults.
                        Select::make('closed_hours')
                            ->label('When closed')
                            ->options(collect(ClosedHours::cases())->mapWithKeys(
                                fn (ClosedHours $choice): array => [$choice->value => $choice->label()],
                            )->all())
                            ->default(ClosedHours::Off->value)
                            ->selectablePlaceholder(false)
                            ->required()
                            // inbound-audio slice 4: this choice needs something to play.
                            // Saved without a file, a caller would be picked up, held in
                            // silence and hung up on — worse than not answering at all.
                            // Reads the just-uploaded file as well as the saved one, so
                            // turning the choice on and uploading in the same save works.
                            ->rules([
                                fn (Get $get, ?Tenant $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                                    if ($value !== ClosedHours::Message->value) {
                                        return;
                                    }

                                    if (filled($get(TenantMedia::ClosedMessage->uploadField()))
                                        || filled($record?->closed_message_path)) {
                                        return;
                                    }

                                    $fail('Upload a closed message below before choosing to play one.');
                                },
                            ])
                            ->helperText('Off: every call is answered, whatever the hours say. No pick-up: outside these hours, on a closed day or on a holiday, the call is not answered, the caller hears a busy tone, and they go on Missed Calls. A message: the call is answered, your closed message plays, the call ends, and they still go on Missed Calls.'),
                        ...BusinessHoursForm::fields(),
                        Repeater::make('holidays')
                            ->label('Holidays')
                            ->simple(
                                // 🔴 Pinned for the same reason as the hour pickers (S118): a
                                // date read in the client's zone and saved in another slips a day.
                                DatePicker::make('date')
                                    ->required()
                                    ->timezone(config('app.timezone')),
                            )
                            ->defaultItems(0)
                            ->addActionLabel('Add a holiday')
                            ->helperText('Closed all day. A night shift that started the evening before still runs to its end.'),
                    ])
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
