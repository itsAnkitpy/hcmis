<?php

namespace App\Filament\Resources\PhoneNumbers\Schemas;

use App\Models\PhoneNumber;
use App\Tenancy\TenantContext;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

/**
 * Add / edit one of a client's phone numbers (B2.3a ND-5).
 *
 * Two refusals live here rather than in anyone's memory:
 *  - the number must be unique across the WHOLE system (ND-2), checked
 *    company-blind so the friendly message beats a raw duplicate-key error;
 *  - a chosen campaign must belong to THIS client (the ND-5 guard).
 */
class PhoneNumberForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('number')
                    ->label('Phone number')
                    ->required()
                    ->maxLength(20)
                    // E.164 with the +, country-agnostic (+1... today, +91... in
                    // production). The + is NOT cosmetic: the dialplan normalises
                    // every arriving call to this spelling and the lookup matches
                    // the stored string exactly, so a number saved without it would
                    // never be found and its calls would all be hung up.
                    ->rule('regex:/^\+[1-9]\d{7,14}$/')
                    ->validationMessages([
                        'regex' => 'Use the full international form, starting with + and the country code — for example +919876543210.',
                    ])
                    ->helperText('Full international form, e.g. +919876543210.')
                    ->rules([
                        fn (?PhoneNumber $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                            // Company-blind on purpose (ND-2): the number is unique across
                            // every client, and the DB index enforces it regardless. Checking
                            // it here only turns a raw duplicate-key crash into a sentence.
                            // The message never names the owning client.
                            $taken = TenantContext::runGlobal(
                                fn (): bool => PhoneNumber::query()
                                    ->where('number', $value)
                                    ->when($record !== null, fn ($query) => $query->whereKeyNot($record->getKey()))
                                    ->exists(),
                            );

                            if ($taken) {
                                $fail('This number is already registered.');
                            }
                        },
                    ]),
                Select::make('campaign_id')
                    ->label('Campaign')
                    ->relationship('campaign', 'name')
                    ->searchable()
                    ->preload()
                    ->placeholder('No campaign yet')
                    ->helperText('Which drive the calls on this number belong to.')
                    // The dropdown only ever lists this client's campaigns (the tenant
                    // scope does that). This rule is what REFUSES a mismatch that arrives
                    // any other way — the ND-5 guard is a refusal, not a filtered list.
                    ->rule(fn (): object => Rule::exists('campaigns', 'id')->where('tenant_id', TenantContext::id()))
                    ->validationMessages([
                        'exists' => 'That campaign belongs to a different client.',
                    ]),
                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true)
                    ->helperText('Switched off, calls to this number are ended cleanly.'),
            ]);
    }
}
