<?php

declare(strict_types=1);

namespace App\Telephony\Flows;

/**
 * One agent's place in a live call's participant set (B2.4b CD-2). The call-tracker
 * used to hold a single connected agent in a scalar (`agentLegId` + `servingAgentId`)
 * plus a transient `transferLegId` for a cold transfer's second agent. Conference needs
 * a STEADY-STATE third party (caller + agent A + agent B all talking), so that fixed
 * pair becomes one caller leg + a SET of these — each either *ringing* (rung, not yet
 * answered) or *connected* (in the voice mixer) — generalised once so transfer,
 * conference, and later supervisor-barge all just add or drop a member.
 *
 * While *ringing*, an added/inbound agent carries the who's-free board reservation we
 * placed the instant we rang them (B2.2b RD-4): released if they never answer (Fold B),
 * cleared without releasing once they connect (their own screen then owns the status).
 * Outbound's agent picked themselves — no reservation — so those refs stay null.
 */
final class AgentLeg
{
    public function __construct(
        public readonly string $legId,
        /**
         * The serving agent's user id — the reserved id (inbound / added agent) or the
         * threaded tag (outbound). Null only for a pre-B2.4a outbound leg that carried no
         * agent id (then the call is simply not transferable — isServingAgent never matches).
         */
        public readonly ?int $userId,
        /** false = ringing (awaiting pickup), true = connected (in the conversation). */
        public bool $connected = false,
        /** The board reservation held while ringing (B2.2b RD-4); null once cleared / for outbound. */
        public ?int $reservedTenantId = null,
        public ?int $reservedAgentId = null,
    ) {}

    /** This agent answered: in the conversation now; the reservation refs are cleared (not released). */
    public function markConnected(): void
    {
        $this->connected = true;
        $this->reservedTenantId = null;
        $this->reservedAgentId = null;
    }
}
