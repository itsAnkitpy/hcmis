<?php

declare(strict_types=1);

namespace App\Enums;

use Illuminate\Support\Facades\URL;
use LogicException;

/**
 * The kinds of sound a client can upload (inbound-audio AU-9: each file is uploaded
 * inside the setting that uses it, so a kind has exactly one job).
 *
 * ONE LIST, THREE READERS. The media route, the conversion job and the client edit
 * form each need to know where a kind's file lives and which columns hold it. Slice 3
 * spelled those out three times for hold music alone; slices 4, 5 and 8 each add
 * another sound, so the list is named once here instead of copied per sound.
 *
 * 🔴 THE CASE VALUE IS PART OF A SIGNED, CACHED WEB ADDRESS. It is the first segment
 * of the media route and the folder the converted file is stored under, so renaming a
 * case breaks every address the voice box already holds — including the one sitting in
 * Asterisk's `musiconhold_entry` row, which is only rewritten on the next upload.
 * `hold-music` is spelled exactly as slice 3 shipped it, on purpose: the route keeps
 * that path byte-for-byte, so staging needs no fix-up.
 *
 * 🔴 SLICE 6 ADDED TWO KINDS THAT ARE NOT ON THE CLIENT. A menu's greeting and a
 * menu key's own file hang off a MENU row, because one client may have several menus
 * and one menu several files — there is no fixed column to put them in. They are on
 * this list anyway, because the two things every sound needs are here and are the same
 * for all five: the word that starts its web address, and whether it is padded. The
 * three CLIENT-COLUMN methods below refuse them outright rather than returning null,
 * so a caller that has not thought about the difference fails loudly instead of
 * writing to the wrong row. `clientKinds()` is what the client's own edit form walks.
 */
enum TenantMedia: string
{
    /** The waiting area's music (slice 3, AU-13). Loops, so it must not be padded. */
    case HoldMusic = 'hold-music';

    /** The "we're closed" announcement (slice 4, AU-2's third choice). Played once. */
    case ClosedMessage = 'closed-message';

    /** The "thanks for waiting" announcement (slice 5, AU-15). Played every 60s of waiting. */
    case WaitingMessage = 'waiting-message';

    /** A menu's greeting (slice 6, AU-17). Lives on the menu row, not the client. */
    case MenuGreeting = 'menu-greeting';

    /** One menu key's own file (slice 6, AU-17/AU-28). Lives inside the menu's options. */
    case MenuOption = 'menu-option';

    /** Said by the three methods below when a menu-owned kind is asked for a client column. */
    private const NOT_ON_A_CLIENT = 'This sound hangs off a menu, not off the client — read it from the menu row.';

    /**
     * The kinds the CLIENT's own edit form builds a section for (AU-9).
     *
     * @return array<int, self>
     */
    public static function clientKinds(): array
    {
        return [self::HoldMusic, self::ClosedMessage, self::WaitingMessage];
    }

    /** Is this sound found by a column on the client row, rather than on a menu? */
    public function isClientOwned(): bool
    {
        return in_array($this, self::clientKinds(), true);
    }

    /** The client column holding the converted file's path, or null when there is none. */
    public function pathColumn(): string
    {
        return match ($this) {
            self::HoldMusic => 'hold_music_path',
            self::ClosedMessage => 'closed_message_path',
            self::WaitingMessage => 'waiting_message_path',
            self::MenuGreeting, self::MenuOption => throw new LogicException(self::NOT_ON_A_CLIENT),
        };
    }

    /** The client column holding the AU-14 rights tick. Audited; see Tenant::activityLogAttributes. */
    public function rightsColumn(): string
    {
        return match ($this) {
            self::HoldMusic => 'hold_music_rights_confirmed',
            self::ClosedMessage => 'closed_message_rights_confirmed',
            self::WaitingMessage => 'waiting_message_rights_confirmed',
            self::MenuGreeting, self::MenuOption => throw new LogicException(self::NOT_ON_A_CLIENT),
        };
    }

    /** The edit form's upload field. Not a column — it is lifted out before the write. */
    public function uploadField(): string
    {
        return match ($this) {
            self::HoldMusic => 'hold_music_upload',
            self::ClosedMessage => 'closed_message_upload',
            self::WaitingMessage => 'waiting_message_upload',
            self::MenuGreeting, self::MenuOption => throw new LogicException(self::NOT_ON_A_CLIENT),
        };
    }

    /**
     * Seconds of silence appended to the converted file.
     *
     * 🔴 ONE SECOND FOR SPEECH, NONE FOR MUSIC, and that is not a preference. S165
     * measured a spoken greeting losing its last word on a real handset without it.
     * Music LOOPS — the voice box cycles a playlist back to its first entry — so the
     * same second would be a hiccup on every lap, and music has no last word to lose.
     */
    public function padSeconds(): int
    {
        return match ($this) {
            self::HoldMusic => 0,
            self::ClosedMessage => 1,
            self::WaitingMessage => 1,
            // Both are speech, so both get the second that stops a handset clipping the
            // last word (S165). Nothing on a menu loops.
            self::MenuGreeting, self::MenuOption => 1,
        };
    }

    /**
     * Where a converted file is stored, and — with the client id — the tail of its web
     * address. The kind comes FIRST so slice 3's shipped path is unchanged.
     */
    public function pathFor(int $tenantId, string $hash): string
    {
        return "{$this->value}/{$tenantId}/{$hash}.wav";
    }

    /**
     * The signed address the voice box fetches a stored file from (AUQ-4), or null
     * when there is no file.
     *
     * 🔴 ONE PLACE BUILDS EVERY SOUND'S ADDRESS. Two would eventually disagree and the
     * signature would stop matching. The client's own sounds reach this through
     * Tenant::mediaUrl and a menu's through Menu::greetingUrl / soundUrlFor, but the
     * signing happens here for all five kinds.
     *
     * No expiry on purpose — see TenantMediaController. The file name IS its SHA-256,
     * so the address changes on every upload and the voice box's year-long cached copy
     * is discarded by the change of address alone.
     */
    public function addressFor(int $tenantId, ?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        return URL::signedRoute('tenants.media', [
            'kind' => $this->value,
            'tenant' => $tenantId,
            'hash' => basename((string) $path, '.wav'),
        ]);
    }
}
