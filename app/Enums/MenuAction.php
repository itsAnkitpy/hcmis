<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What one key on a client's menu does (inbound-audio AU-17).
 *
 * 🔴 THE VALUE IS STORED INSIDE `menus.options`, so renaming a case rewrites every
 * client's saved menu. Treat it as shipped the moment it reaches staging.
 */
enum MenuAction: string
{
    /** Today's path: reserve a free agent, or the waiting area if nobody is free. */
    case TalkToAgent = 'agent';

    /**
     * Ring one of the client's departments: its free agents first, then anyone once the
     * client's wait has passed (inbound-audio slice 7). The key carries `department_id`.
     */
    case RingDepartment = 'department';

    /** Play this option's own file, then end the call (AU-17). Needs a sound. */
    case HearMessage = 'message';

    /** Add the caller to this client's do-not-call list, then end the call (AU-28). */
    case RemoveFromList = 'remove';

    /**
     * Take the caller to the message pad (slice 8, AU-29). No sound of its own: the
     * greeting is the client's. A client with voicemail off sends the caller to a desk.
     */
    case LeaveMessage = 'voicemail';

    public function label(): string
    {
        return match ($this) {
            self::TalkToAgent => 'Talk to an agent',
            self::RingDepartment => 'Ring a department',
            self::HearMessage => 'Hear a message, then the call ends',
            self::RemoveFromList => 'Take me off your call list',
            self::LeaveMessage => 'Leave a message (needs the client\'s voicemail on)',
        };
    }

    /**
     * Does this key need a sound of its own before it can be saved?
     *
     * "Hear a message" IS the file — without one the caller hears nothing and the call
     * ends, which reads as a dropped call. The do-not-call confirmation is optional by
     * AU-28: the number is added either way, and a client with nothing recorded simply
     * gets the quieter version.
     */
    public function requiresSound(): bool
    {
        return $this === self::HearMessage;
    }

    /**
     * Does the caller get what they rang for, so the call ends here rather than going
     * to a desk? These keep them OFF Missed Calls (AU-26), and they are the keys with a
     * sound of their own. A message left is NOT one: the caller still wants ringing back
     * (AU-32), and the greeting belongs to the client.
     */
    public function servesCaller(): bool
    {
        return in_array($this, [self::HearMessage, self::RemoveFromList], true);
    }
}
