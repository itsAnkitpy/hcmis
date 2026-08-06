<?php

declare(strict_types=1);

namespace App\Telephony;

use App\Models\PhoneNumber;
use App\Tenancy\TenantContext;

/**
 * The number -> client lookup (B2.3a ND-1): "a call arrived on this number — which
 * client is it for?". The replacement for the one hardcoded client the dialplan used
 * to stamp on every inbound call, and the reason a second client can now have a
 * phone number without anyone editing a file on the server.
 *
 * COMPANY-BLIND ON PURPOSE. Finding the client is the whole point, so the read cannot
 * already be scoped to one — it runs in the ownerless posture (TenantContext::runGlobal),
 * the AttachRecordingToCall precedent. NOT cross(): that fires the tenant.cross_access
 * tripwire, which must stay rare to be a useful signal, and this runs on every inbound
 * call. The read is safe to widen because ND-2 makes `number` unique across the whole
 * system: exactly one client can own a number, so there is no ambiguity to resolve and
 * nothing of another client's is exposed by finding it.
 *
 * Caller -> lead ("who is calling?") is deliberately NOT here (ND-6). That lookup is
 * ambiguous — one phone can be a lead for two clients — and only becomes safe once
 * this one has fixed the client. Client first, caller second.
 */
class NumberDirectory
{
    /**
     * The row owning this dialled number, or null when the number is unknown to us
     * or switched off. Null is the ND-4 clean end: the caller is hung up, not routed
     * to a guess.
     */
    public function resolve(?string $dialledNumber): ?PhoneNumber
    {
        if ($dialledNumber === null || $dialledNumber === '') {
            return null;
        }

        return TenantContext::runGlobal(
            fn (): ?PhoneNumber => PhoneNumber::query()
                ->where('number', $dialledNumber)
                ->where('is_active', true)
                ->first(),
        );
    }
}
