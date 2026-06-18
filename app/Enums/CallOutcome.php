<?php

namespace App\Enums;

/**
 * What happened on the line (B3 D5). NOT `blocked` — a do-not-call dial places no
 * call, so it gets no `calls` row (stays audit-only). `abandoned` is reserved for
 * the trunk-era listener (an inbound caller who hung up before an agent answered);
 * the web write-path only ever sets `answered` / `no_answer`.
 *
 * STAGING NOTE (B3 S39 — see PRD/phase-2/b3-calls-table.md top banner): in v1 this
 * value is AGENT-REPORTED and provisional — derived from the picked disposition's
 * `is_contact` flag (`true → answered`, else `no_answer`), and left NULL for
 * dispositionless ad-hoc/unmatched calls. The browser's `answered` flag is NOT
 * usable here (it tracks the auto-answered agent leg, always true at wrap-up).
 * When the real phone line (SIP trunk + DIDs) lands on staging, the watcher
 * (`telephony:listen`) learns the TRUE line-result and OVERRIDES this column via
 * the UUID correlation seam (CP-B3-2); the disposition stays the separate business
 * record. Treat a v1 value as provisional, not authoritative.
 */
enum CallOutcome: string
{
    case Answered = 'answered';
    case NoAnswer = 'no_answer';
    case Abandoned = 'abandoned';

    public function label(): string
    {
        return match ($this) {
            self::Answered => 'Answered',
            self::NoAnswer => 'No answer',
            self::Abandoned => 'Abandoned',
        };
    }
}
