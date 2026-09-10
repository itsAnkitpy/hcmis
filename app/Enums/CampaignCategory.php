<?php

namespace App\Enums;

/**
 * What kind of call a campaign makes, in the only sense the regulator cares
 * about (DIAL-1 DQ-5, and DQ-6's G2 and G3).
 *
 * This is NOT CampaignTemplate — that says what agents do on the call. This
 * says whether TCCCPR 2018's time bands apply. Promotional calls are barred
 * outside 10:00–21:00; service and transactional calls are exempt from time
 * bands entirely. Without this column an 08:00 start looks identical whether
 * it is legal or a fine.
 *
 * Defaults to Promotional (DIAL-1 F3): the stricter of the two. A campaign
 * somebody switches to progressive without thinking about its category is
 * exactly the one that should be clamped, and a service floor correcting one
 * dropdown is a cheaper mistake than a marketing floor discovering the rule
 * from a penalty.
 *
 * Deliberately not copied from anywhere: DialShree has no equivalent field
 * (S143 checked — its lineage is a US dialer, so its compliance knobs are
 * drop-rate and area-code call times, not TRAI's).
 */
enum CampaignCategory: string
{
    /**
     * The band promotional calls are confined to (DQ-6 G2).
     *
     * TCCCPR 2018's own time-band table makes 00:00–10:00 and 21:00–24:00 default
     * OFF for every subscriber, registered or not. 🔴 It is 10:00, not the 9-to-9
     * figure repeated loosely in secondary sources — that is the SMS/DLT convention.
     *
     * Service and transactional calls are exempt from time bands entirely, which is
     * why this lives on the enum and not on the campaign: it is a property of the
     * category, and slice 3 reads the same two constants when it decides whether a
     * campaign may dial right now.
     */
    public const PROMOTIONAL_START = '10:00';

    public const PROMOTIONAL_END = '21:00';

    case Promotional = 'promotional';
    case Service = 'service';

    public function label(): string
    {
        return match ($this) {
            self::Promotional => 'Promotional — marketing, 10:00–21:00 only',
            self::Service => 'Service — support and transactional, no time limit',
        };
    }
}
