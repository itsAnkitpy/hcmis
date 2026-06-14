<?php

use App\Actions\AdvanceLeadStatus;
use App\Enums\LeadStatus;

/**
 * The C2-lite forward-only funnel nudge (CP3 decision B). Pure function, no DB —
 * one row per cell of the status table, proving it advances where it should and
 * never moves a lead backward or out of Closed.
 */
it('advances the lead status forward-only on a wrap-up', function (LeadStatus $current, bool $isContact, LeadStatus $expected) {
    expect((new AdvanceLeadStatus)($current, $isContact))->toBe($expected);
})->with([
    'New + contact -> Contacted' => [LeadStatus::New, true, LeadStatus::Contacted],
    'New + non-contact -> In Progress' => [LeadStatus::New, false, LeadStatus::InProgress],
    'In Progress + contact -> Contacted' => [LeadStatus::InProgress, true, LeadStatus::Contacted],
    'In Progress + non-contact -> unchanged' => [LeadStatus::InProgress, false, LeadStatus::InProgress],
    'Contacted + contact -> unchanged' => [LeadStatus::Contacted, true, LeadStatus::Contacted],
    'Contacted + non-contact -> unchanged (never backward)' => [LeadStatus::Contacted, false, LeadStatus::Contacted],
    'Closed + contact -> unchanged (never auto-reopened)' => [LeadStatus::Closed, true, LeadStatus::Closed],
    'Closed + non-contact -> unchanged' => [LeadStatus::Closed, false, LeadStatus::Closed],
]);
