<?php

use App\Enums\CampaignTemplate;

return [

    /*
    |--------------------------------------------------------------------------
    | Disposition templates
    |--------------------------------------------------------------------------
    |
    | Starter disposition sets per campaign template (BRD FR-LC06). Used by
    | M3 wizard step 4 — pre-filled based on the campaign template chosen in
    | step 1, fully editable from there on.
    |
    | Each row: { code, label, is_contact, is_sale }.
    |   - is_contact = "we actually spoke to a human"
    |   - is_sale    = "the contact ended in a positive business outcome"
    |
    | Add / change / remove freely — these are templates, not gospel.
    |
    */

    'disposition_templates' => [
        CampaignTemplate::OutboundSales->value => [
            ['code' => 'INTERESTED', 'label' => 'Interested', 'is_contact' => true, 'is_sale' => false],
            ['code' => 'CALLBACK', 'label' => 'Callback requested', 'is_contact' => true, 'is_sale' => false],
            ['code' => 'NOT_INTERESTED', 'label' => 'Not interested', 'is_contact' => true, 'is_sale' => false],
            ['code' => 'SOLD', 'label' => 'Sold', 'is_contact' => true, 'is_sale' => true],
            ['code' => 'DNC', 'label' => 'Do not call', 'is_contact' => true, 'is_sale' => false],
            ['code' => 'NO_ANSWER', 'label' => 'No answer', 'is_contact' => false, 'is_sale' => false],
            ['code' => 'VOICEMAIL', 'label' => 'Voicemail left', 'is_contact' => false, 'is_sale' => false],
        ],

        CampaignTemplate::CustomerCareCallback->value => [
            ['code' => 'RESOLVED', 'label' => 'Issue resolved', 'is_contact' => true, 'is_sale' => true],
            ['code' => 'ESCALATED', 'label' => 'Escalated to L2', 'is_contact' => true, 'is_sale' => false],
            ['code' => 'CALLBACK', 'label' => 'Callback requested', 'is_contact' => true, 'is_sale' => false],
            ['code' => 'INFORMED', 'label' => 'Customer informed', 'is_contact' => true, 'is_sale' => false],
            ['code' => 'NO_ANSWER', 'label' => 'No answer', 'is_contact' => false, 'is_sale' => false],
            ['code' => 'VOICEMAIL', 'label' => 'Voicemail left', 'is_contact' => false, 'is_sale' => false],
        ],

        CampaignTemplate::CodVerification->value => [
            ['code' => 'CONFIRMED', 'label' => 'COD confirmed', 'is_contact' => true, 'is_sale' => true],
            ['code' => 'REJECTED', 'label' => 'COD rejected', 'is_contact' => true, 'is_sale' => false],
            ['code' => 'RESCHEDULE', 'label' => 'Reschedule delivery', 'is_contact' => true, 'is_sale' => false],
            ['code' => 'NO_ANSWER', 'label' => 'No answer', 'is_contact' => false, 'is_sale' => false],
            ['code' => 'INVALID_NUMBER', 'label' => 'Invalid number', 'is_contact' => false, 'is_sale' => false],
        ],

        CampaignTemplate::Ndr->value => [
            ['code' => 'REATTEMPT', 'label' => 'Re-attempt delivery', 'is_contact' => true, 'is_sale' => false],
            ['code' => 'ADDRESS_UPDATE', 'label' => 'Address update', 'is_contact' => true, 'is_sale' => false],
            ['code' => 'REFUSED', 'label' => 'Refused', 'is_contact' => true, 'is_sale' => false],
            ['code' => 'OUT_OF_AREA', 'label' => 'Out of service area', 'is_contact' => true, 'is_sale' => false],
            ['code' => 'NO_ANSWER', 'label' => 'No answer', 'is_contact' => false, 'is_sale' => false],
        ],

        CampaignTemplate::EdtechEnrollment->value => [
            ['code' => 'ENROLLED', 'label' => 'Enrolled', 'is_contact' => true, 'is_sale' => true],
            ['code' => 'CALLBACK', 'label' => 'Callback requested', 'is_contact' => true, 'is_sale' => false],
            ['code' => 'INTERESTED', 'label' => 'Interested', 'is_contact' => true, 'is_sale' => false],
            ['code' => 'NOT_INTERESTED', 'label' => 'Not interested', 'is_contact' => true, 'is_sale' => false],
            ['code' => 'NO_ANSWER', 'label' => 'No answer', 'is_contact' => false, 'is_sale' => false],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Break category defaults
    |--------------------------------------------------------------------------
    |
    | Starter break types seeded for every tenant on creation (BK-1). The six
    | come from Dialshree via BPO operations (2026-07-07). Deliberately no
    | time_limit_minutes here — real per-type times are an open ops question;
    | admins type them into the Break Categories screen when they arrive.
    | Editing this list never retro-changes existing tenants (seed-once).
    |
    */

    'break_category_defaults' => [
        ['code' => 'BIO_BREAK', 'label' => 'Bio Break'],
        ['code' => 'PAUSE', 'label' => 'Pause'],
        ['code' => 'TRAINING', 'label' => 'Training'],
        ['code' => 'BRIEFING', 'label' => 'Briefing'],
        ['code' => 'LUNCH_BREAK', 'label' => 'Lunch Break'],
        ['code' => 'TEA_BREAK', 'label' => 'Tea Break'],
    ],

];
