<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tenants\Schemas;

use App\Enums\TenantMedia;
use App\Models\Tenant;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * One upload section per sound a client can have (inbound-audio AU-9, AU-11, AU-14).
 *
 * Slice 3 wrote this once for hold music; slice 4 is the second sound and slices 5 and
 * 8 are already written into the plan, so the three fields are built here instead of
 * copied per section. Same move as BusinessHoursForm, which the same form already uses.
 *
 * Each section is the same three things: the upload, the rights tick box, and a plain
 * browser player for what is in use today. Only the WORDING differs by sound, and the
 * wording lives here with the rest of the form's text rather than on the enum, which
 * stays structural.
 */
class TenantMediaSection
{
    /**
     * @param  array<int, Component>  $leading  fields the sound's own setting needs above its upload (slice 8's switch)
     */
    public static function make(TenantMedia $kind, array $leading = []): Section
    {
        return Section::make(self::title($kind))
            ->description(self::description($kind))
            ->schema([
                ...$leading,

                // The field is deliberately never pre-filled with the current file:
                // it means "replace this sound", and the player below is what shows
                // what is in use today. Filling it would also push Filament to build
                // a preview address for a file on a private local disk, which the
                // local driver cannot produce.
                FileUpload::make($kind->uploadField())
                    ->label(self::uploadLabel($kind))
                    ->disk(config('telephony.media.disk'))
                    // A random per-upload sub-folder avoids name clashes while keeping
                    // the original name, whose extension is how the audio tool knows
                    // what it is reading (same shape as the lead import).
                    ->directory(fn (?Tenant $record): string => $kind->value.'/pending/'.$record?->id.'/'.Str::random(8))
                    ->visibility('private')
                    ->previewable(false)
                    ->acceptedFileTypes(['audio/mpeg', 'audio/wav', 'audio/x-wav'])
                    ->maxSize(10240)
                    ->validationMessages([
                        'mimetypes' => self::noun($kind).' must be an MP3 or a WAV file.',
                        'max' => self::noun($kind).' must be 10 MB or smaller.',
                    ])
                    ->helperText('MP3 or WAV, up to 10 MB. It is converted to phone quality after you save, so it may take a moment to appear below.'),

                // AU-14. Required only when something is actually being uploaded, so
                // saving any other setting on this page does not demand it again. Who
                // ticked it and when comes from the activity log, which keeps this
                // column's changes forever (D-M7-3).
                //
                // 🔴 THE RULE IS THE SAME FOR EVERY SOUND, THE SENTENCE IS NOT. AU-14's
                // reasoning is about music licensing, but a client who uploads a song as
                // their closed message is exactly the case it exists for — so the tick
                // stays. Putting the music tariff note under a spoken announcement would
                // just teach people to tick without reading, which weakens it where it
                // counts.
                Checkbox::make($kind->rightsColumn())
                    ->label(self::rightsLabel($kind))
                    ->accepted(fn (Get $get): bool => filled($get($kind->uploadField())))
                    ->validationMessages([
                        'accepted' => self::rightsError($kind),
                    ])
                    ->helperText(self::rightsHelp($kind)),

                // AU-9 wants a play button, and Filament's upload field has none for
                // audio (a gap raised against Filament repeatedly and still open), so
                // this is a plain browser player pointed at the same signed address the
                // voice box uses. TenantMediaController lets a head-office user through
                // that address for exactly this.
                Placeholder::make($kind->value.'_player')
                    ->label('Playing today')
                    ->visible(fn (?Tenant $record): bool => filled($record?->{$kind->pathColumn()}))
                    ->content(fn (Tenant $record): HtmlString => new HtmlString(
                        '<audio controls preload="none" src="'.e((string) $record->mediaUrl($kind)).'"></audio>',
                    ))
                    ->columnSpanFull(),
            ])
            ->hiddenOn('create');
    }

    private static function uploadLabel(TenantMedia $kind): string
    {
        return match ($kind) {
            TenantMedia::HoldMusic => 'Upload music',
            TenantMedia::ClosedMessage => 'Upload the message',
            TenantMedia::WaitingMessage => 'Upload the message',
            TenantMedia::VoicemailGreeting => 'Upload the greeting',
        };
    }

    private static function title(TenantMedia $kind): string
    {
        return match ($kind) {
            TenantMedia::HoldMusic => 'Hold music',
            TenantMedia::ClosedMessage => 'Closed message',
            TenantMedia::WaitingMessage => 'Waiting message',
            TenantMedia::VoicemailGreeting => 'Voicemail',
        };
    }

    private static function description(TenantMedia $kind): string
    {
        return match ($kind) {
            TenantMedia::HoldMusic => 'What a caller hears while they wait for an agent. Leave empty and they hear the standard music.',
            TenantMedia::ClosedMessage => 'What a caller hears outside business hours when "When closed" is set to play a message. The call ends once it has played.',
            TenantMedia::WaitingMessage => 'What a waiting caller hears every minute while they hold. The music pauses for it and resumes afterwards. Leave empty and they hear music only.',
            TenantMedia::VoicemailGreeting => 'Off until you switch it on. Then a caller can leave a message after the closed message, when the maximum hold runs out, or from a menu key. They hear this greeting, a beep, and have up to three minutes. Messages play from Missed Calls.',
        };
    }

    private static function noun(TenantMedia $kind): string
    {
        return match ($kind) {
            TenantMedia::HoldMusic => 'Hold music',
            TenantMedia::ClosedMessage => 'The closed message',
            TenantMedia::WaitingMessage => 'The waiting message',
            TenantMedia::VoicemailGreeting => 'The greeting',
        };
    }

    private static function rightsLabel(TenantMedia $kind): string
    {
        return match ($kind) {
            TenantMedia::HoldMusic => 'We have the rights to play this music to callers',
            TenantMedia::ClosedMessage => 'We have the right to play this recording to callers',
            TenantMedia::WaitingMessage => 'We have the right to play this recording to callers',
            TenantMedia::VoicemailGreeting => 'We have the right to play this recording to callers',
        };
    }

    private static function rightsError(TenantMedia $kind): string
    {
        return match ($kind) {
            TenantMedia::HoldMusic => 'Confirm you have the rights to play this music to callers.',
            TenantMedia::ClosedMessage => 'Confirm you have the right to play this recording to callers.',
            TenantMedia::WaitingMessage => 'Confirm you have the right to play this recording to callers.',
            TenantMedia::VoicemailGreeting => 'Confirm you have the right to play this recording to callers.',
        };
    }

    private static function rightsHelp(TenantMedia $kind): string
    {
        return match ($kind) {
            TenantMedia::HoldMusic => 'On-hold music is licensable in India in its own right — IPRS names "Music on Hold" in its own tariff. Ticking this is recorded against your name.',
            TenantMedia::ClosedMessage => 'A recording of your own words needs nothing else. If it contains music or anything you did not record, you need the rights to it. Ticking this is recorded against your name.',
            TenantMedia::WaitingMessage => 'A recording of your own words needs nothing else. If it contains music or anything you did not record, you need the rights to it. Ticking this is recorded against your name.',
            TenantMedia::VoicemailGreeting => 'A recording of your own words needs nothing else. If it contains music or anything you did not record, you need the rights to it. Ticking this is recorded against your name.',
        };
    }
}
