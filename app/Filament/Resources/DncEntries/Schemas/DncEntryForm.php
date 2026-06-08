<?php

namespace App\Filament\Resources\DncEntries\Schemas;

use App\Enums\DncSource;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * DNC entry create / edit form (M6). A phone number on this client's own
 * do-not-call list. The number is normalized on save (model mutator), so
 * scopedUnique enforces "one number per client, once" (D-M6-4). expires_at is
 * optional and not enforced yet (D-M6-8); reason is free-text context (D-M6-7).
 */
class DncEntryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('phone')
                    ->tel()
                    ->required()
                    ->maxLength(20)
                    ->scopedUnique()
                    ->helperText('The customer number to never dial. Spaces and dashes are removed automatically.'),
                Select::make('source')
                    ->options(collect(DncSource::cases())
                        ->mapWithKeys(fn (DncSource $s): array => [$s->value => $s->label()])
                        ->all())
                    ->default(DncSource::Manual->value)
                    ->required()
                    ->native(false),
                Textarea::make('reason')
                    ->rows(3)
                    ->maxLength(500)
                    ->helperText('Optional — why this number is on the list (e.g. customer request, ticket #).'),
                DateTimePicker::make('expires_at')
                    ->label('Expires at')
                    ->helperText('Optional. Shown as Expired after this time — not auto-removed (enforcement is a later phase).'),
            ]);
    }
}
