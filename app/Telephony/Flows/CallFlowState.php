<?php

declare(strict_types=1);

namespace App\Telephony\Flows;

/**
 * Where the one call-to-agent flow sits in its lifecycle (B4 CP2a, generalised
 * to outbound at B-outbound M2). The flow drives exactly one call at a time in
 * v1 (concurrency is B2), so a single state value is all the machine needs.
 *
 * Two entry paths share the same Idle and InCall ends; only the ringing stage
 * differs by direction:
 *   - inbound:  caller answered -> RingingAgent    (the agent's phone rings)
 *   - outbound: agent leg is up -> RingingCustomer (the customer's phone rings)
 *
 * B2.4a/B2.4b add one extra stage that hangs off InCall, not Idle: the live call is
 * still joined to its serving agent(s) while a SECOND agent (B) is being rung in. The
 * ring is identical for a cold transfer and a 3-way conference (the caller is never left
 * alone); an intent flag on the handler forks only what happens when B answers — drop A
 * (transfer) or keep A (conference). The machine leaves InCall for AddingAgent and
 * returns to InCall either way: B answered, or B didn't (the call is unchanged).
 */
enum CallFlowState
{
    /** No call in flight; ready to take a fresh caller or an agent-initiated dial. */
    case Idle;

    /** Inbound: caller answered; the agent's phone is ringing (awaiting pickup or timeout). */
    case RingingAgent;

    /** Outbound: agent leg is up; the customer's phone is ringing (awaiting pickup or timeout). */
    case RingingCustomer;

    /**
     * Progressive dial (DIAL-1 A3): the DIALER placed the customer leg and nobody is on
     * our side of it yet — a desk is booked on the board, but no agent leg exists. The
     * customer answering is what starts the ring (A4); the leg ending first is a plain
     * no-answer.
     *
     * 🔴 Deliberately NOT RingingCustomer, which looks identical and is not. That state
     * means an agent is already on the line, so a leg ending there is a customer who
     * abandoned, and it writes CallOutcome::Abandoned. Abandoned is the number DP-12a
     * counts against the 3% legal cap — filing ordinary no-answers there would put a
     * compliant floor over the line on paper. Most progressive dials go unanswered.
     */
    case DialingCustomer;

    /** The caller is joined to one or more connected agents and being recorded. */
    case InCall;

    /**
     * Adding a second agent to a live call (B2.4b CD-5; was B2.4a's Transferring): the
     * caller stays joined to the serving agent (A) while a free agent (B) is being rung.
     * B answering forks on the handler's intent — transfer (drop A) or conference (keep
     * A, the 3-way). B not answering (or the caller leaving) returns to InCall. The
     * caller is never alone — the never-strand rule, shared with the cold transfer.
     */
    case AddingAgent;

    /**
     * The client is closed and has chosen to say so (inbound-audio slice 4, AU-2's third
     * choice): the caller has been answered and their closed message is playing. The
     * call ends when the message finishes, or when the caller hangs up on it.
     *
     * 🔴 A DEAD END, not a stage on the way anywhere. No desk is booked, no agent will
     * be rung, and the missed-call row is already written — it is filed at the door, the
     * moment the hours say closed, so no ending can lose it. Reached only from the
     * inbound door, and left only by teardown.
     */
    case PlayingClosedMessage;

    /**
     * The caller is answered and holding with music on, waiting for a desk to free
     * up (B2.3b-i QD-2). Reached from BOTH ways a caller used to be hung up on:
     * nobody was free when they arrived, and an agent let their phone ring out.
     *
     * The waiting LINE needs no data structure of its own: the switchboard already
     * holds one handler per live call in arrival order, so "everyone waiting, oldest
     * first" is simply its Waiting handlers in order (QD-3's sweep). Leaves for
     * RingingAgent when the sweep pairs the caller with a freed agent, or ends when
     * the caller gives up / the client's maximum hold time runs out.
     */
    case Waiting;

    /**
     * The caller is answered and the client's spoken menu is asking them to press a key
     * (inbound-audio slice 6, AU-17 … AU-24). Reached from the inbound door only, after
     * the hours check (AU-21) and before any desk is looked at.
     *
     * 🔴 TIMED BY THE SOUND, NOT BY OUR HEARTBEAT. The greeting and five seconds of
     * silence are handed over as ONE play, so that play finishing with no key pressed IS
     * the timeout (S163). The listener's heartbeat is five seconds on a quiet line, so
     * using it would have made the wait anywhere from five to ten.
     *
     * NOT A DEAD END, unlike the closed message: a caller who misses twice goes on to a
     * desk with "No choice made" (AU-23/AU-24), so every exit the waiting room has is
     * reachable from here. It is also the one answered state the sweep would otherwise
     * ignore, which is why the maximum-hold check names it — a lost "sound finished"
     * event would otherwise leave the caller on a live line with no ending at all.
     */
    case InMenu;

    /**
     * The caller chose a key that answers them and then ends the call — "hear a message",
     * or the confirmation after "take me off your list" (AU-17, AU-28).
     *
     * 🔴 A DEAD END, the same shape as PlayingClosedMessage, and separate from it for one
     * reason: while the menu is still asking, a finished sound means the caller said
     * nothing. Here it means they were served and the call is over. One state cannot
     * carry both readings, and guessing from the playback id alone would break the moment
     * a later slice plays anything else in the menu.
     *
     * Their call record is written the moment the key is taken, before a sound plays, so
     * no ending can lose it — the same discipline slice 4 uses at the door.
     */
    case PlayingMenuMessage;
}
