<?php

declare(strict_types=1);

namespace App\Telephony;

/**
 * The one clean layer between the app and the voice engine (B1 D3).
 *
 * The contract speaks call-language — "call", "leg", "conversation" — never
 * Asterisk-language ("channel", "bridge", "Stasis"). Only the implementation
 * knows how the engine spells these words, so a future vendor swap is one new
 * class and one config line, not an app-wide rewrite.
 *
 * Two rules carried from the lab (A3 Stage 1 + the 2026-06-12 experiment):
 *  - a 2xx HTTP reply means "the engine heard me", never "it happened" —
 *    completion comes from the event pipe (the telephony:listen command);
 *  - leg/conversation ids are opaque strings the engine handed us; the app
 *    stores and passes them back, it never builds or parses them.
 *
 * Verb set = exactly what the lab proved. B2 (call flows) grows this list;
 * B1 locked the shape, not the final word count.
 */
interface TelephonyProvider
{
    /**
     * Start a new outgoing call leg and return its id. The tag is how we
     * recognise our own calls on the event pipe — legs we placed arrive
     * tagged, outside calls arrive with no tag.
     *
     * $callerId, when given, is the number shown to the dialled endpoint — B4
     * D4 rides the customer's number to the agent's screen this way. Left null,
     * the engine uses its own default and no number is presented.
     *
     * $tagDetails, when given, ride alongside the tag back to the listener on the
     * event pipe as ordered extra values next to it. Outbound uses them to carry
     * the customer's number AND the call's tracking number (the B3 UUID) on the
     * agent leg, so the flow can dial the customer and key the recording without
     * any web<->listener side channel (CP-O0-proven comma-split). Empty, only the
     * bare tag rides.
     *
     * @param  array<int, string>  $tagDetails
     */
    public function placeCall(string $destination, string $tag, ?string $callerId = null, int $timeoutSeconds = 30, array $tagDetails = []): string;

    /** Pick up a ringing leg. */
    public function answer(string $legId): void;

    /** Put two legs into one conversation (they hear each other); returns the conversation id. */
    public function join(string $legIdA, string $legIdB): string;

    /**
     * Add a leg to a conversation that ALREADY exists (B2.4a TD-3): the bridge
     * surgery a cold transfer leans on — slip a freshly-answered agent into the
     * live conversation before dropping the old one, so the caller never hears a
     * gap. The conversation is a mixing one (join() makes it that way), so it
     * holds more than two legs for the moment both agents are in it.
     */
    public function addToBridge(string $conversationId, string $legId): void;

    /**
     * Remove a leg from a conversation without ending it (B2.4a TD-3): the other
     * half of the transfer surgery — take the old agent out once the new one is
     * in. The leg itself is hung up separately; this only detaches it from the
     * conversation.
     */
    public function removeFromBridge(string $conversationId, string $legId): void;

    /**
     * Send a free-form control signal to the running call-control listener (B2.4a
     * TD-4): the web→listener side channel that needs no database table and no
     * poll. The listener receives it on the event pipe it already holds; the
     * $details ride along so it can find the live call the signal is about (B2.4a
     * uses the agent's user id + tenant id). Reusable beyond transfer (TD-7) — the
     * signal $name is what the listener routes on. The web calls this directly,
     * the same web→provider precedent as outbound placeCall.
     *
     * @param  array<string, string>  $details
     */
    public function signal(string $name, array $details): void;

    /** Move a leg out of its conversation and send it to another destination (e.g. an extension). */
    public function transfer(string $legId, string $conversationId, string $destination): void;

    /** End a leg's call. Works on any leg we know the id of, even after it left us. */
    public function hangup(string $legId): void;

    /** Fold away an emptied conversation so the voice box doesn't accumulate them. */
    public function endConversation(string $conversationId): void;

    /**
     * Record both sides of a leg's call (B1 D4): what that person says and
     * what they hear, as two separate files — the pair a queued job later
     * merges into the stereo MP3 (caller left, agent right, PW5-4).
     */
    public function startRecording(string $legId, string $name): RecordingSession;

    /** Stop both sides of a recording session and release its listener legs. */
    public function stopRecording(RecordingSession $session): void;

    /**
     * Pull a finished recording's raw audio off the voice box over HTTP —
     * proven in the lab; lets the app server live apart from the voice box.
     */
    public function fetchRecording(string $recordingName): string;
}
