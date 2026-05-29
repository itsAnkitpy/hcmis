<?php

namespace App\Enums;

/**
 * Campaign templates HC supports out of the box (BRD FR-LC06).
 *
 * Picked at onboarding (M3 wizard step 1) — the choice pre-fills the
 * disposition starter set in step 4. Fully editable on the tenant after
 * onboarding, and new templates can be added without a migration.
 *
 * NB: this is NOT the client's industry vertical (the BRD lists those
 * separately as D2C / E-com / 3PL / EdTech). This is the *kind of work*
 * agents do on the calls.
 */
enum CampaignTemplate: string
{
    case OutboundSales = 'outbound_sales';
    case CustomerCareCallback = 'customer_care_callback';
    case CodVerification = 'cod_verification';
    case Ndr = 'ndr';
    case EdtechEnrollment = 'edtech_enrollment';

    public function label(): string
    {
        return match ($this) {
            self::OutboundSales => 'Outbound Sales',
            self::CustomerCareCallback => 'Customer Care Callback',
            self::CodVerification => 'COD Verification',
            self::Ndr => 'NDR (Non-Delivery Report)',
            self::EdtechEnrollment => 'EdTech Enrollment',
        };
    }
}
