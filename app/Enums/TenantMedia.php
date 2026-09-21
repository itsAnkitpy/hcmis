<?php

declare(strict_types=1);

namespace App\Enums;

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
 * Slice 6's menu greeting and per-option files do NOT belong here — they hang off a
 * menu record, not off the client, so they cannot be found by a client-column lookup.
 */
enum TenantMedia: string
{
    /** The waiting area's music (slice 3, AU-13). Loops, so it must not be padded. */
    case HoldMusic = 'hold-music';

    /** The "we're closed" announcement (slice 4, AU-2's third choice). Played once. */
    case ClosedMessage = 'closed-message';

    /** The "thanks for waiting" announcement (slice 5, AU-15). Played every 60s of waiting. */
    case WaitingMessage = 'waiting-message';

    /** The client column holding the converted file's path, or null when there is none. */
    public function pathColumn(): string
    {
        return match ($this) {
            self::HoldMusic => 'hold_music_path',
            self::ClosedMessage => 'closed_message_path',
            self::WaitingMessage => 'waiting_message_path',
        };
    }

    /** The client column holding the AU-14 rights tick. Audited; see Tenant::activityLogAttributes. */
    public function rightsColumn(): string
    {
        return match ($this) {
            self::HoldMusic => 'hold_music_rights_confirmed',
            self::ClosedMessage => 'closed_message_rights_confirmed',
            self::WaitingMessage => 'waiting_message_rights_confirmed',
        };
    }

    /** The edit form's upload field. Not a column — it is lifted out before the write. */
    public function uploadField(): string
    {
        return match ($this) {
            self::HoldMusic => 'hold_music_upload',
            self::ClosedMessage => 'closed_message_upload',
            self::WaitingMessage => 'waiting_message_upload',
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
}
