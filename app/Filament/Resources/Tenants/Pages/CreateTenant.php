<?php

namespace App\Filament\Resources\Tenants\Pages;

use App\Enums\CampaignTemplate;
use App\Enums\RoleName;
use App\Filament\Resources\Tenants\TenantResource;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\Actions\SendUserInvite;
use App\Tenancy\Settings\BusinessHoursForm;
use App\Tenancy\TenantContext;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Wizard\Step;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * M3 onboarding wizard (Checkpoint B).
 *
 * Six steps walk an HC admin through everything needed to make a new client
 * operational without voice: brand → hours → SLAs → dispositions → scripts
 * → agents. Nothing is written until Finish — abandoning halfway leaves no
 * rows behind.
 *
 * On Finish we create the tenant (TenantObserver auto-provisions the 5
 * per-client roles into its team), then drop into TenantContext::run() so
 * agent role assignments resolve through TenantTeamResolver to the new
 * tenant's team — not team 0.
 *
 * Agent invite email + first-password flow lands in Checkpoint C (D-M3-3).
 * Until then, the wizard creates User rows with a random unusable password
 * and email_verified_at = null; admin shares the access path out-of-band.
 */
class CreateTenant extends CreateRecord
{
    use CreateRecord\Concerns\HasWizard;

    protected static string $resource = TenantResource::class;

    protected function getSteps(): array
    {
        return [
            Step::make('Brand')
                ->description('Who is this client?')
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (?string $state, callable $set) => $set('slug', Str::slug((string) $state))),
                    TextInput::make('slug')
                        ->required()
                        ->maxLength(255)
                        ->unique(Tenant::class, 'slug')
                        ->helperText('URL-safe id. Lower-case letters, numbers, dashes.'),
                    Select::make('campaign_template')
                        ->label('Campaign template')
                        ->options(collect(CampaignTemplate::cases())->mapWithKeys(
                            fn (CampaignTemplate $t): array => [$t->value => $t->label()]
                        )->all())
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (?string $state, callable $set): void {
                            // Seed step 4's dispositions repeater the moment a
                            // template is picked (or changed). Repeater `default()`
                            // only fires at form initialization, so it can't react
                            // to a later pick — this hook does.
                            $set('dispositions', self::dispositionDefaultsFor($state));
                        })
                        ->helperText('What kind of work do agents do on this client? Pre-fills disposition defaults in step 4. Editable from there on.'),
                ])
                ->columns(2),

            Step::make('Hours')
                ->description('When does this client operate?')
                ->schema(BusinessHoursForm::fields())
                ->columns(1),

            Step::make('SLAs')
                ->description('Default service-level rules. Editable per campaign later.')
                ->schema([
                    TextInput::make('sla.first_attempt_window_min')
                        ->label('First attempt window (minutes)')
                        ->numeric()
                        ->minValue(1)
                        ->default(60)
                        ->required(),
                    TextInput::make('sla.max_attempts')
                        ->label('Max attempts per lead')
                        ->numeric()
                        ->minValue(1)
                        ->default(5)
                        ->required(),
                    TextInput::make('sla.callback_honour_window_hours')
                        ->label('Callback honour window (hours)')
                        ->numeric()
                        ->minValue(1)
                        ->default(24)
                        ->required(),
                ])
                ->columns(3),

            Step::make('Dispositions')
                ->description('Outcomes agents can mark on a call. Defaults from the campaign template picked above.')
                ->schema([
                    Repeater::make('dispositions')
                        ->label('')
                        ->schema([
                            TextInput::make('code')
                                ->required()
                                ->maxLength(40)
                                ->helperText('Short uppercase code, e.g. CALLBACK.'),
                            TextInput::make('label')
                                ->required()
                                ->maxLength(60),
                            Toggle::make('is_contact')
                                ->label('Counts as contact')
                                ->default(false),
                            Toggle::make('is_sale')
                                ->label('Counts as sale')
                                ->default(false),
                        ])
                        ->columns(4)
                        ->reorderable()
                        ->itemLabel(fn (array $state): ?string => $state['label'] ?? $state['code'] ?? null)
                        ->minItems(1),
                ]),

            Step::make('Scripts')
                ->description('Reference text agents see while on a call. Free-form.')
                ->schema([
                    Textarea::make('scripts.opening')
                        ->label('Opening')
                        ->rows(3),
                    Textarea::make('scripts.objection')
                        ->label('Objection handling')
                        ->rows(3),
                    Textarea::make('scripts.closing')
                        ->label('Closing')
                        ->rows(3),
                ]),

            Step::make('Agents')
                ->description('Starting HC staff for this client. More can be added later. Skip if you want to set up users separately.')
                ->schema([
                    Repeater::make('agents')
                        ->label('')
                        ->schema([
                            TextInput::make('name')->required()->maxLength(255),
                            TextInput::make('email')
                                ->required()
                                ->email()
                                ->maxLength(255)
                                ->unique(User::class, 'email'),
                            Select::make('role_name')
                                ->label('Role')
                                ->options(collect(RoleName::perClient())->mapWithKeys(
                                    fn (RoleName $r): array => [$r->value => Str::headline($r->value)]
                                )->all())
                                ->default(RoleName::Agent->value)
                                ->required(),
                        ])
                        ->columns(3)
                        ->itemLabel(fn (array $state): ?string => $state['name'] ?? $state['email'] ?? null)
                        ->defaultItems(0)
                        ->addActionLabel('Add agent'),
                ]),
        ];
    }

    /**
     * Build the Tenant + side effects on Finish. Tenant is created first
     * (its observer provisions roles); the rest runs inside
     * TenantContext::run() so role assignments resolve to its team.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $settingsPayload = [
            'hours' => BusinessHoursForm::fromForm($data['hours'] ?? []),
            'sla' => $data['sla'] ?? [],
            'dispositions' => $data['dispositions'] ?? [],
            'scripts' => array_filter([
                'opening' => $data['scripts']['opening'] ?? null,
                'objection' => $data['scripts']['objection'] ?? null,
                'closing' => $data['scripts']['closing'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''),
            'portal_access' => false,
            'campaign_template' => $data['campaign_template'] ?? null,
        ];

        $tenant = Tenant::create([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'settings' => $settingsPayload,
        ]);

        $newUsers = [];

        TenantContext::run($tenant->getKey(), function () use ($tenant, $data, &$newUsers): void {
            foreach (($data['agents'] ?? []) as $agent) {
                $user = User::create([
                    'name' => $agent['name'],
                    'email' => $agent['email'],
                    'password' => Hash::make(Str::password(40)),
                ]);

                $user->tenants()->attach($tenant->getKey());
                $user->assignRole($agent['role_name']);

                $newUsers[] = $user;
            }
        });

        // Best-effort invites. The whole create runs inside Filament's DB
        // transaction (CreateRecord), so a thrown mailer error would roll back
        // the tenant + roles + users. trySend swallows failures; we collect the
        // addresses that didn't go out and tell the admin to use Resend.
        $failedInvites = [];

        foreach ($newUsers as $newUser) {
            if (! SendUserInvite::trySend($newUser, $tenant)) {
                $failedInvites[] = $newUser->email;
            }
        }

        if ($failedInvites !== []) {
            Notification::make()
                ->warning()
                ->title('Some invite emails could not be sent')
                ->body('The client and its users were created. Use "Resend invite" on the Users tab for: '.implode(', ', $failedInvites))
                ->persistent()
                ->send();
        }

        return $tenant;
    }

    /**
     * Pull the disposition starter set for the chosen campaign template out
     * of the config; empty default when nothing has been picked yet.
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function dispositionDefaultsFor(?string $templateValue): array
    {
        if ($templateValue === null) {
            return [];
        }

        return config("hcims.disposition_templates.{$templateValue}", []);
    }
}
