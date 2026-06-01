<?php

namespace App\Enums;

/**
 * A lead's position in the fixed lifecycle funnel (D-M4-3). This is the same
 * shape for every client — the per-client, configurable part is the call
 * *outcome* (the Disposition the lead points at), not this status.
 *
 * DNC is deliberately NOT a funnel position: it is a cross-cutting flag whose
 * data model lands in M6, not a stage a lead moves through. In M4 there are no
 * calls, so transitions are manual / data-level; automatic attempt-driven
 * transitions arrive with the agent desktop in Phase 2.
 */
enum LeadStatus: string
{
    case New = 'new';
    case InProgress = 'in_progress';
    case Contacted = 'contacted';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::InProgress => 'In Progress',
            self::Contacted => 'Contacted',
            self::Closed => 'Closed',
        };
    }
}
