<?php

namespace App\Mail;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to a newly-created user (M3 Checkpoint C). Includes a signed URL to
 * the accept-invite page where they set their first password and get
 * email_verified_at stamped.
 */
class UserInviteMailable extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $invitee,
        public string $acceptUrl,
        public ?Tenant $assignedTenant = null,
    ) {}

    public function envelope(): Envelope
    {
        $appName = config('app.name');

        return new Envelope(
            subject: "You're invited to {$appName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.user-invite',
            with: [
                'invitee' => $this->invitee,
                'acceptUrl' => $this->acceptUrl,
                'assignedTenant' => $this->assignedTenant,
                'appName' => config('app.name'),
            ],
        );
    }
}
