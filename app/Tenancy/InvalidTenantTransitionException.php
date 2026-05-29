<?php

namespace App\Tenancy;

use App\Enums\TenantStatus;
use RuntimeException;

/**
 * Raised by Tenant::transitionTo() when a lifecycle transition is not allowed
 * (e.g. archived → anything, or active → active). Surfaces the attempted move
 * so logs and tests have something to assert on.
 */
class InvalidTenantTransitionException extends RuntimeException
{
    public function __construct(
        public readonly TenantStatus $from,
        public readonly TenantStatus $to,
    ) {
        parent::__construct(sprintf(
            'Tenant transition not allowed: %s -> %s.',
            $from->value,
            $to->value,
        ));
    }
}
