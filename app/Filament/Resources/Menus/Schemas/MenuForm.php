<?php

declare(strict_types=1);

namespace App\Filament\Resources\Menus\Schemas;

use App\Enums\MenuAction;
use App\Enums\TenantMedia;
use App\Models\Department;
use App\Models\Menu;
use App\Tenancy\TenantContext;
use Closure;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Build one spoken menu (inbound-audio slice 6, AU-17 … AU-27). Head office only —
 * MenuPolicy, not the shared operational-data map.
 *
 * The upload shape is the client form's, deliberately: the same file field, the same
 * rights tick (AU-14), the same plain browser player pointed at the same signed address
 * the voice box uses. It is NOT built by TenantMediaSection, because that builder reads
 * and writes CLIENT COLUMNS and a menu's sounds have none.
 *
 * ONE LEVEL ONLY (AU-20) and SINGLE KEYS ONLY (AU-27), so there is no "go back", no
 * depth limit and no loop check to write. Both are enforced by what this form offers.
 */
class MenuForm
{
    /** 0-9, star and hash — the twelve keys a phone has (AU-27). */
    private const KEY_PATTERN = '/^[0-9*#]$/';

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Head office has no client in context (see MenuResource), so a menu
                // says which client it is for. Shown only in the cross-client posture,
                // which is the only posture head office is ever in — a client-scoped
                // request would stamp it from the context like every other record.
                Select::make('tenant_id')
                    ->label('Client')
                    ->relationship('tenant', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->visible(fn (): bool => TenantContext::isCrossTenant())
                    ->disabledOn('edit')
                    ->helperText('Which client\'s callers hear this menu.'),

                TextInput::make('name')
                    ->label('Menu name')
                    ->required()
                    ->maxLength(120)
                    ->helperText('Only staff see this. Callers hear the greeting below.'),

                Section::make('Greeting')
                    ->description('What every caller on this menu hears first. They can press a key while it is still playing.')
                    ->schema([
                        FileUpload::make('greeting_upload')
                            ->label('Upload the greeting')
                            ->disk(config('telephony.media.disk'))
                            ->directory(fn (): string => TenantMedia::MenuGreeting->value.'/pending/'.Str::random(12))
                            ->visibility('private')
                            ->previewable(false)
                            ->acceptedFileTypes(['audio/mpeg', 'audio/wav', 'audio/x-wav'])
                            ->maxSize(10240)
                            // 🔴 REQUIRED UNTIL THERE IS ONE. A menu with no greeting plays
                            // silence at the caller and then puts them through, which reads
                            // as a dropped call — so it is refused here rather than handled
                            // quietly on the phone. The flow still falls back safely if a
                            // file goes missing later; this stops the obvious way in.
                            ->required(fn (?Menu $record): bool => blank($record?->greeting_path))
                            ->validationMessages([
                                'mimetypes' => 'The greeting must be an MP3 or a WAV file.',
                                'max' => 'The greeting must be 10 MB or smaller.',
                                'required' => 'A menu needs a greeting — without one the caller hears silence.',
                            ])
                            ->helperText('MP3 or WAV, up to 10 MB. It is converted to phone quality after you save, so it may take a moment to appear below.'),

                        Checkbox::make('greeting_rights_confirmed')
                            ->label('We have the right to play this recording to callers')
                            ->accepted(fn (Get $get): bool => filled($get('greeting_upload')))
                            ->validationMessages([
                                'accepted' => 'Confirm you have the right to play this recording to callers.',
                            ])
                            ->helperText('A recording of your own words needs nothing else. If it contains music or anything you did not record, you need the rights to it. Ticking this is recorded against your name.'),

                        Placeholder::make('greeting_player')
                            ->label('Playing today')
                            ->visible(fn (?Menu $record): bool => filled($record?->greeting_path))
                            ->content(fn (Menu $record): HtmlString => new HtmlString(
                                '<audio controls preload="none" src="'.e((string) $record->greetingUrl()).'"></audio>',
                            ))
                            ->columnSpanFull(),
                    ]),

                Section::make('Keys')
                    ->description('What each key does. A caller who presses nothing, or a key that is not here, hears the greeting once more and is then put through to an agent.')
                    ->schema([
                        Repeater::make('options')
                            ->hiddenLabel()
                            ->addActionLabel('Add a key')
                            ->reorderable(false)
                            ->schema([
                                TextInput::make('key')
                                    ->label('Key')
                                    ->required()
                                    ->maxLength(1)
                                    ->rule('regex:'.self::KEY_PATTERN)
                                    ->validationMessages([
                                        'regex' => 'A key is one character: 0-9, * or #.',
                                    ])
                                    // Two keys with the same character means the second is
                                    // unreachable, and nothing on the phone would ever say so.
                                    ->rules([
                                        fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                            $keys = collect($get('../../options') ?? [])
                                                ->map(fn (array $option): ?string => $option['key'] ?? null)
                                                ->filter()
                                                ->all();

                                            if (count(array_keys($keys, $value, true)) > 1) {
                                                $fail('Each key can only be used once on a menu.');
                                            }
                                        },
                                    ])
                                    ->columnSpan(1),

                                TextInput::make('label')
                                    ->label('What it is')
                                    ->required()
                                    ->maxLength(120)
                                    ->helperText('Shown to the agent when the phone rings, and saved on the call.')
                                    ->columnSpan(2),

                                Select::make('action')
                                    ->label('What it does')
                                    ->options(Menu::actionOptions())
                                    ->required()
                                    ->live()
                                    ->columnSpan(2),

                                // slice 7: only the menu's OWN client's switched-on departments
                                // are offered, and the rule refuses anything else a crafted
                                // request names. Head office has no client in context, so
                                // the client is the menu's, read off the form.
                                Select::make('department_id')
                                    ->label('Department')
                                    ->options(fn (Get $get, ?Menu $record): array => self::departmentsFor($record?->tenant_id ?? $get('../../tenant_id')))
                                    ->visible(fn (Get $get): bool => MenuAction::tryFrom((string) $get('action')) === MenuAction::RingDepartment)
                                    ->required()
                                    ->rules([
                                        fn (Get $get, ?Menu $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                                            if (! array_key_exists((int) $value, self::departmentsFor($record?->tenant_id ?? $get('../../tenant_id')))) {
                                                $fail('Pick a switched-on department of this menu\'s client.');
                                            }
                                        },
                                    ])
                                    ->columnSpanFull(),

                                // Round-trips the converted file so an edit that touches
                                // nothing else keeps it. The conversion job overwrites it
                                // by KEY after the save.
                                Hidden::make('sound_path'),

                                FileUpload::make('sound_upload')
                                    ->label('Sound for this key')
                                    ->disk(config('telephony.media.disk'))
                                    ->directory(fn (): string => TenantMedia::MenuOption->value.'/pending/'.Str::random(12))
                                    ->visibility('private')
                                    ->previewable(false)
                                    ->acceptedFileTypes(['audio/mpeg', 'audio/wav', 'audio/x-wav'])
                                    ->maxSize(10240)
                                    ->visible(fn (Get $get): bool => MenuAction::tryFrom((string) $get('action'))?->servesCaller() === true)
                                    // "Hear a message" IS the file (AU-17). The do-not-call
                                    // confirmation is optional (AU-28) — the number is added
                                    // either way.
                                    ->required(fn (Get $get): bool => MenuAction::tryFrom((string) $get('action'))?->requiresSound() === true
                                        && blank($get('sound_path')))
                                    ->validationMessages([
                                        'mimetypes' => 'The sound must be an MP3 or a WAV file.',
                                        'max' => 'The sound must be 10 MB or smaller.',
                                        'required' => 'This key plays a message, so it needs a recording.',
                                    ])
                                    ->helperText('MP3 or WAV, up to 10 MB.')
                                    ->columnSpanFull(),

                                Checkbox::make('sound_rights_confirmed')
                                    ->label('We have the right to play this recording to callers')
                                    ->visible(fn (Get $get): bool => MenuAction::tryFrom((string) $get('action'))?->servesCaller() === true)
                                    ->accepted(fn (Get $get): bool => filled($get('sound_upload')))
                                    ->validationMessages([
                                        'accepted' => 'Confirm you have the right to play this recording to callers.',
                                    ])
                                    ->columnSpanFull(),
                            ])
                            ->columns(5)
                            ->itemLabel(fn (array $state): ?string => filled($state['key'] ?? null)
                                ? trim(($state['key'] ?? '').' — '.($state['label'] ?? ''), ' —')
                                : null),
                    ]),
            ]);
    }

    /**
     * The departments a key on this client's menu may ring: switched on, and the
     * client's own. Head office sees every client's rows (cross-client posture), so the
     * client filter here is what keeps one client's key off another's department.
     *
     * @return array<int, string>
     */
    private static function departmentsFor(mixed $tenantId): array
    {
        $tenantId ??= TenantContext::id();

        if (blank($tenantId)) {
            return [];
        }

        return Department::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
