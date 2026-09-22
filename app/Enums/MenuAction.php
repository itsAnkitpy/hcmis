<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What one key on a client's menu does (inbound-audio AU-17).
 *
 * FOUR THINGS WERE DECIDED, THREE ARE BUILT HERE. Voicemail is the fourth and lands
 * with slice 8; agent groups are slice 7 and become one more case on this list without
 * the menu itself changing (slice 7's note in the plan). Both are deliberately absent
 * rather than present-and-broken — a key a client can pick that does nothing is worse
 * than a key they cannot pick yet.
 *
 * 🔴 THE VALUE IS STORED INSIDE `menus.options`, so renaming a case rewrites every
 * client's saved menu. Treat it as shipped the moment it reaches staging.
 */
enum MenuAction: string
{
    /** Today's path: reserve a free agent, or the waiting area if nobody is free. */
    case TalkToAgent = 'agent';

    /** Play this option's own file, then end the call (AU-17). Needs a sound. */
    case HearMessage = 'message';

    /** Add the caller to this client's do-not-call list, then end the call (AU-28). */
    case RemoveFromList = 'remove';

    public function label(): string
    {
        return match ($this) {
            self::TalkToAgent => 'Talk to an agent',
            self::HearMessage => 'Hear a message, then the call ends',
            self::RemoveFromList => 'Take me off your call list',
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
     * to a desk? Both of these keep them OFF Missed Calls (AU-26).
     */
    public function servesCaller(): bool
    {
        return $this !== self::TalkToAgent;
    }
}
