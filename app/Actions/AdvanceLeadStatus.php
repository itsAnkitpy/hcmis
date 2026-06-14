<?php

namespace App\Actions;

use App\Enums\LeadStatus;

/**
 * The C2-lite forward-only status nudge a wrap-up applies to the matched lead
 * (CP3 decision B; honours D-M4-3's "automatic attempt-driven transitions arrive
 * with the agent desktop in Phase 2").
 *
 * The one product-semantics rule of CP3, isolated here so the change-prone funnel
 * logic lives in a single greppable, unit-testable place — a pure function with
 * no model, no DB, no side effects.
 *
 *   current      | contact outcome (is_contact) | non-contact outcome
 *   -------------+------------------------------+--------------------
 *   New          | Contacted                    | In Progress
 *   In Progress  | Contacted                    | (unchanged)
 *   Contacted    | (unchanged)                  | (unchanged)
 *   Closed       | (never auto-changed)         | (never auto-changed)
 *
 * Forward-only and monotonic: a later non-contact call can never drag a lead
 * back, and Closed is always a human/admin decision — never an auto-transition.
 */
class AdvanceLeadStatus
{
    public function __invoke(LeadStatus $current, bool $isContact): LeadStatus
    {
        // Closed is terminal for this auto-nudge — only a human/admin reopens.
        if ($current === LeadStatus::Closed) {
            return LeadStatus::Closed;
        }

        // A contact outcome lifts the lead to Contacted, the furthest this nudge
        // ever reaches (New and In Progress both advance; Contacted holds).
        if ($isContact) {
            return LeadStatus::Contacted;
        }

        // A non-contact outcome only pulls a brand-new lead into the pipeline; it
        // never moves an already-advanced lead (forward-only / monotonic).
        return $current === LeadStatus::New ? LeadStatus::InProgress : $current;
    }
}
