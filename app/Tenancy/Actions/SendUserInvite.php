<?php

namespace App\Tenancy\Actions;

use App\Mail\UserInviteMailable;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Generates a signed accept-invite URL (72-hour expiry) and dispatches the
 * UserInviteMailable to the invitee. M3 Checkpoint C.
 *
 * Called explicitly by the three user-create entry points (wizard agents
 * step, per-tenant Add new user, global Users Create) and by the Resend
 * action on both surfaces. Never auto-fired by the User factory — invites
 * stay opt-in so tests don't spam Mailpit.
 */
class SendUserInvite
{
    /**
     * Build the signed URL for the given user; embeds user id + email as
     * the signed signature, expires in 72 hours.
     */
    public static function signedAcceptUrl(User $user): string
    {
        return URL::temporarySignedRoute(
            'invite.accept',
            now()->addHours(72),
            [
                'user' => $user->getKey(),
                'email' => $user->email,
            ],
        );
    }

    public function __invoke(User $invitee, ?Tenant $assignedTenant = null): void
    {
        $url = self::signedAcceptUrl($invitee);

        Mail::to($invitee->email)->send(new UserInviteMailable(
            invitee: $invitee,
            acceptUrl: $url,
            assignedTenant: $assignedTenant,
        ));
    }

    public static function run(User $invitee, ?Tenant $assignedTenant = null): void
    {
        (new self)($invitee, $assignedTenant);
    }

    /**
     * Best-effort send: never throws. The three creation paths call the invite
     * from inside Filament's create transaction (CreateRecord wraps
     * handleRecordCreation), so a thrown mailer error would roll back the whole
     * tenant/user creation. This swallows the failure, logs it, and returns
     * false so the caller can surface a "use Resend" warning instead. Once the
     * mailable is queued (M5/Horizon) the send leaves the request entirely and
     * this becomes belt-and-suspenders.
     */
    public static function trySend(User $invitee, ?Tenant $assignedTenant = null): bool
    {
        try {
            self::run($invitee, $assignedTenant);

            return true;
        } catch (Throwable $e) {
            Log::warning('user.invite.send_failed', [
                'user_id' => $invitee->getKey(),
                'email' => $invitee->email,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
