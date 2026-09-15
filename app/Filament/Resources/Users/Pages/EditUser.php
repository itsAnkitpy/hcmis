<?php

namespace App\Filament\Resources\Users\Pages;

use App\Audit\Audit;
use App\Enums\RoleName;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Telephony\AgentPhoneWriter;
use App\Tenancy\TenantContext;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Spatie\Permission\PermissionRegistrar;

/**
 * Edit page for the global Users resource. Backs the form's synthetic fields
 * (email_verified toggle, global_roles checkbox list) — they aren't real
 * columns/relations, so we translate them on fill and on save.
 */
class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /**
     * The three phone actions: issue (SM-1), retire and re-issue (SEC-1, PP-19).
     *
     * 🔴 Retiring is a deliberate act and not an automatic one, because there is
     * nothing automatic to hang it on: HCIMS cannot switch off or delete a user
     * account at all (RV-8). Removing someone from a client is the wrong trigger —
     * that is also how a role gets corrected, and PP-9 exists to stop a correction
     * churning a phone.
     *
     * Exactly one is ever on screen, and the three conditions partition every user:
     * no number at all (issue), a number with no key (re-issue), a working key
     * (retire). Re-issue is not a nicety: without it, a phone retired by mistake
     * could never be brought back, because provisioning skips anyone who already
     * holds a number.
     *
     * This page is already walled to global staff (UserPolicy::update), so none of
     * the three carries a gate of its own.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            // SM-1: the only path in the admin to a phone for someone who is not an
            // agent. Becoming an agent provisions one automatically
            // (AssignTenantRole::givePhoneIfNowAnAgent); Team Leader and QC trigger
            // nothing, so a supervisor who needs to hear a live call had no route to
            // a phone at all. The writer is already role-agnostic — this is the
            // missing trigger, not missing machinery.
            //
            // 🔴 A phone is NOT a place in the call queue. Who may be handed a call is
            // decided by presence (AgentRouter::reserveFreeAgent reads the board, not
            // roles), and only a screen that announces itself Ready creates a board
            // row. Issuing a phone here writes no board row, so it cannot put anyone
            // in line for a customer (SM-3, pinned by SupervisorPhoneTest).
            Action::make('issue_phone')
                ->label('Issue a phone')
                ->icon(Heroicon::OutlinedPhone)
                ->visible(fn (): bool => $this->record->sip_extension === null)
                ->requiresConfirmation()
                ->modalHeading('Give this person a phone?')
                ->modalDescription('They get the next free number and a key of their own, and they can register it from Live Agents. Their roles do not change, and this does not put them in line to be handed calls.')
                ->modalSubmitActionLabel('Issue the phone')
                ->action(function (): void {
                    /** @var User $user */
                    $user = $this->record;

                    // ponytail: no 23505 catch. Two admins issuing in the same instant
                    // both read the same number and the second write fails loudly on
                    // the unique index (PP-1) — a 500 on a button, nothing written,
                    // click again. AssignTenantRole catches it because a 500 there
                    // would also lose the role write; here there is nothing to lose.
                    // Add the catch if this ever stops being a one-admin action.
                    $extension = app(AgentPhoneWriter::class)->provisionFor($user);

                    // No audit line of its own: `sip_extension` is on the user's
                    // activity-logged attributes, so the number landing on their row
                    // records itself. Retire and re-issue need one precisely because
                    // nothing on the row moves for either.
                    Notification::make()
                        ->success()
                        ->title("Phone {$extension} issued")
                        ->body('They can register it from the Live Agents screen. It stays theirs — the number is never handed to anyone else.')
                        ->send();
                }),

            Action::make('retire_phone')
                ->label("Retire this agent's phone")
                ->icon(Heroicon::OutlinedPhoneXMark)
                ->color('danger')
                ->visible(fn (): bool => app(AgentPhoneWriter::class)->hasKey($this->record))
                ->requiresConfirmation()
                ->modalHeading("Retire this agent's phone?")
                ->modalDescription(fn (): HtmlString => self::retireWarning($this->record))
                ->modalSubmitActionLabel('Retire the phone')
                ->action(function (): void {
                    /** @var User $user */
                    $user = $this->record;
                    $extension = (string) $user->sip_extension;

                    app(AgentPhoneWriter::class)->retireFor($user);
                    Audit::phoneRetired($user, $extension);

                    Notification::make()
                        ->success()
                        ->title("Phone {$extension} retired")
                        ->body('Their key is destroyed. The number stays retired to them and their call records are untouched.')
                        ->send();
                }),

            Action::make('reissue_phone')
                ->label("Re-issue this agent's phone")
                ->icon(Heroicon::OutlinedPhone)
                ->visible(fn (): bool => $this->record->sip_extension !== null
                    && ! app(AgentPhoneWriter::class)->hasKey($this->record))
                ->requiresConfirmation()
                ->modalHeading('Give this agent a working phone again?')
                ->modalDescription(fn (): string => 'They get a new key on their own number '.$this->record->sip_extension.', and nothing else changes. They will need to open the agent console again for it to take effect.')
                ->modalSubmitActionLabel('Re-issue the phone')
                ->action(function (): void {
                    /** @var User $user */
                    $user = $this->record;
                    $extension = (string) $user->sip_extension;

                    app(AgentPhoneWriter::class)->provisionFor($user);
                    Audit::phoneReissued($user, $extension);

                    Notification::make()
                        ->success()
                        ->title("Phone {$extension} re-issued")
                        ->body('They can sign in to the agent console again.')
                        ->send();
                }),
        ];
    }

    /**
     * What the warning says before a phone is retired (PP-20).
     *
     * Three things, in the order someone needs them: what survives, what the number
     * does afterwards, and where to get their calls as a file first. 🔴 The link
     * goes to the Call export screen that shipped in August and already filters by
     * agent — do not build a second download here (RV-9).
     */
    private static function retireWarning(User $user): HtmlString
    {
        $extension = e((string) $user->sip_extension);
        $export = route('filament.admin.pages.call-export-report');

        return new HtmlString(
            'Their key is destroyed, so nothing can sign in as this phone again. '
            .'<strong>Every call they made or took stays exactly as it is</strong>, and '
            ."number {$extension} stays retired to them — it is never given to anyone else. "
            .'If you want their calls as a file first, download them from '
            ."<a href=\"{$export}\" target=\"_blank\" class=\"underline font-medium\">Reports → Call export</a> "
            .'before you retire this phone.'
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var User $user */
        $user = $this->record;

        $data['email_verified'] = $user->email_verified_at !== null;
        $data['global_roles'] = self::currentGlobalRoleValues($user);

        return $data;
    }

    /**
     * Refuse to strip super_admin from the last super admin — that would lock
     * everyone out of the global resources (no recovery without a seeder/tinker).
     * Runs before the record is written (EditRecord fires beforeSave inside
     * getState(), ahead of handleRecordUpdate + afterSave), so halting here
     * leaves nothing half-saved.
     */
    protected function beforeSave(): void
    {
        $selected = array_values(array_intersect(
            collect($this->form->getRawState()['global_roles'] ?? [])
                ->filter(fn ($v): bool => $v !== null && $v !== '')
                ->all(),
            RoleName::globalValues(),
        ));

        $superAdmin = RoleName::SuperAdmin->value;

        $removingSuperAdmin = ! in_array($superAdmin, $selected, strict: true);
        $currentlySuperAdmin = in_array($superAdmin, self::currentGlobalRoleValues($this->record), strict: true);

        if ($removingSuperAdmin && $currentlySuperAdmin && self::superAdminCount() <= 1) {
            Notification::make()
                ->danger()
                ->title('Cannot remove the last super admin')
                ->body('At least one super admin must remain. Grant super_admin to another user first, then retry.')
                ->send();

            $this->halt();
        }
    }

    /**
     * Both synthetic fields (email_verified toggle, global_roles checkbox
     * list) are dehydrated(false), so they never reach $data. Handle them
     * here from the raw form state — same hook as global roles for clarity.
     */
    protected function afterSave(): void
    {
        $raw = $this->form->getRawState();

        // --- Email verified toggle → email_verified_at column
        $shouldBeVerified = (bool) ($raw['email_verified'] ?? false);
        $currentlyVerified = $this->record->email_verified_at !== null;
        if ($shouldBeVerified !== $currentlyVerified) {
            $this->record->forceFill([
                'email_verified_at' => $shouldBeVerified ? now() : null,
            ])->save();
        }

        // --- Global roles sync → model_has_roles with team_id = 0
        $selected = collect($raw['global_roles'] ?? [])
            ->filter(fn ($v) => $v !== null && $v !== '')
            ->values()
            ->all();
        $selected = array_values(array_intersect($selected, RoleName::globalValues()));

        TenantContext::forget();
        self::syncGlobalRoles($this->record, $selected);
    }

    /**
     * How many users hold super_admin at the global team (id 0). Used to block
     * removing the role from the last one standing.
     */
    private static function superAdminCount(): int
    {
        return (int) DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', User::class)
            ->where('model_has_roles.team_id', 0)
            ->where('roles.name', RoleName::SuperAdmin->value)
            ->distinct()
            ->count('model_has_roles.model_id');
    }

    /**
     * @return array<int, string>
     */
    private static function currentGlobalRoleValues(User $user): array
    {
        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $user->getKey())
            ->where('model_has_roles.model_type', User::class)
            ->where('model_has_roles.team_id', 0)
            ->pluck('roles.name')
            ->all();
    }

    /**
     * Sync the user's global-role assignments (team_id = 0) to exactly the
     * given set. Direct table mutation + cache invalidate — same pattern as
     * UsersRelationManager, avoiding spatie's contextual team scoping.
     *
     * @param  array<int, string>  $roleNames
     */
    private static function syncGlobalRoles(User $user, array $roleNames): void
    {
        DB::table('model_has_roles')
            ->where('model_id', $user->getKey())
            ->where('model_type', User::class)
            ->where('team_id', 0)
            ->delete();

        foreach ($roleNames as $name) {
            $roleId = DB::table('roles')
                ->where('name', $name)
                ->where('guard_name', 'web')
                ->where('team_id', 0)
                ->value('id');

            if ($roleId === null) {
                continue;
            }

            DB::table('model_has_roles')->insert([
                'role_id' => $roleId,
                'model_id' => $user->getKey(),
                'model_type' => User::class,
                'team_id' => 0,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
