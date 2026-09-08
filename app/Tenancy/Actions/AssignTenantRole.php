<?php

namespace App\Tenancy\Actions;

use App\Audit\Audit;
use App\Enums\RoleName;
use App\Models\User;
use App\Telephony\AgentPhoneWriter;
use App\Tenancy\TenantContext;
use Filament\Notifications\Notification;
use Illuminate\Database\QueryException;

/**
 * The one place a per-client role is written (SEC-1, PP-8).
 *
 * Four screens can make someone an Agent: attach and change-role on the user's
 * Clients panel, attach and change-role on the client's Users panel, the inline
 * "Add new user" on that same panel, and the onboarding wizard's agents step.
 * Slice 3 hooked the phone onto one of them, which is why a role saved on the
 * client's page produced no phone at all. Every path now comes through here, so
 * the question "did this screen remember?" cannot be asked again.
 *
 * Same doctrine as ProvisionTenantRoles next door: every code path that grants a
 * per-client role ends up with the same three things — the role, the audit line,
 * and a phone if the role is Agent.
 *
 * 🔴 The caller must already be inside TenantContext::run($tenant->id, …), because
 * $user->assignRole() resolves its team through TenantTeamResolver and the audit
 * line reads the same context.
 */
class AssignTenantRole
{
    public function __invoke(User $user, string $roleName): void
    {
        app(ClearTenantRoles::class)($user);

        $user->assignRole($roleName);

        Audit::roleGranted($user, $roleName, TenantContext::id());

        $this->givePhoneIfNowAnAgent($user, $roleName);
    }

    /**
     * Becoming an agent gets you a working phone, with nobody touching the switch
     * (SEC-1, PP-8).
     *
     * 🔴 The reverse never happens on its own (PP-9). Losing the agent role, or being
     * removed from the client entirely, leaves the phone alone: a role correction is
     * not a departure, and churning someone's extension would scramble the call
     * history that is read back against it. Retiring is a deliberate action (PP-19).
     *
     * The "already has one" check reads the passed record straight. Livewire re-fetches
     * the owner record from the database on every request, so it cannot be stale —
     * measured, not assumed, by the test that allocates an extension behind an open
     * page. That test is what would catch it if the framework ever stopped doing so,
     * because a second number here would be genuinely free and the unique index on
     * users.sip_extension would not fire.
     */
    private function givePhoneIfNowAnAgent(User $user, string $roleName): void
    {
        if ($roleName !== RoleName::Agent->value) {
            return;
        }

        if ($user->sip_extension !== null) {
            return;
        }

        try {
            app(AgentPhoneWriter::class)->provisionFor($user);
        } catch (QueryException $e) {
            // 23505 is Postgres for "that number is already taken" — two admins
            // allocating in the same moment, which PP-1's unique index is there to
            // catch loudly rather than let two browsers share one phone. Anything
            // else is a real fault and must stay loud on its own terms.
            if ((string) $e->getCode() !== '23505') {
                throw $e;
            }

            report($e);

            // Worded once here rather than four times on the screens, because all
            // four say exactly the same thing. ImportLeadsJob and CallExportController
            // already raise Filament notifications from outside app/Filament.
            Notification::make()
                ->warning()
                ->title('Role saved, but the phone was not created')
                ->body('Someone else was given a number at the same moment. Open Change role, pick Agent again, and they will get one.')
                ->persistent()
                ->send();
        }
    }
}
