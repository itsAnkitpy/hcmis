<?php

namespace App\Filament\Resources\Users\Pages;

use App\Enums\RoleName;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Tenancy\Actions\SendUserInvite;
use App\Tenancy\TenantContext;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Create page for the global Users resource — HighlandConnect's own cross-client
 * staff only (super admin / HC admin / ops manager). These hold a global role at
 * the reserved team (id 0) and no single-client membership.
 *
 * Client staff (agents, TLs, QC, …) are NOT created here — they're added in
 * client context via the onboarding wizard or a client's "Users" tab, which
 * land the role in the right team and attach membership. Keeping this page
 * global-only removes the global-vs-client role conflict and the "created with
 * no role, can't log in" orphan by construction.
 *
 * Password = random unusable; email_verified_at = null. The invite flow
 * (Checkpoint C) sets the real password and verifies on accept.
 */
class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    public function getSubheading(): ?string
    {
        return 'For HighlandConnect staff who work across all clients. To add an agent or other staff to a specific client, use that client\'s "Users" tab instead.';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identity')
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('email')
                        ->label('Email address')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->unique(User::class, 'email'),
                ])
                ->columns(2),

            Section::make('HC role')
                ->description('Operates across every client; holds no single-client membership. Exactly one is required.')
                ->schema([
                    Select::make('global_role')
                        ->label('Role')
                        ->options(collect(RoleName::globals())
                            ->mapWithKeys(fn (RoleName $r): array => [$r->value => Str::headline($r->value)])
                            ->all())
                        ->required(),
                ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make(Str::password(40)),
        ]);

        // Global role lands at the reserved global team (no tenant context).
        $globalRole = $data['global_role'] ?? null;
        if ($globalRole !== null && in_array($globalRole, RoleName::globalValues(), strict: true)) {
            TenantContext::forget();
            $user->assignRole($globalRole);
        }

        // Best-effort: the create runs inside Filament's DB transaction, so a
        // mailer error must not roll back the new user. Warn instead.
        if (! SendUserInvite::trySend($user)) {
            Notification::make()
                ->warning()
                ->title('Invite email could not be sent')
                ->body('The user was created. Use "Resend invite" from the Users list to try again.')
                ->persistent()
                ->send();
        }

        return $user;
    }
}
