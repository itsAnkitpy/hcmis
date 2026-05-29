<?php

namespace App\Enums;

/**
 * Lifecycle states for a client account (BR-07; M3 §5.1).
 *
 * `active` is the only state the panel bridge (SetCurrentTenant) will bind into
 * TenantContext — a non-active membership is skipped and the user falls back to
 * an active client, or to no context at all. NOTE: a dedicated "this client is
 * suspended" block screen is NOT built yet; a user whose only membership is
 * non-active still passes canAccessPanel and lands context-less. That screen is
 * deferred to M4 (when agent-facing resources exist). Transitions go through
 * Tenant::transitionTo() so the rules live in one place.
 */
enum TenantStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Archived => 'Archived',
        };
    }

    /**
     * Whether the panel bridge may bind this tenant into TenantContext. Only
     * active tenants get to serve traffic.
     */
    public function isOperable(): bool
    {
        return $this === self::Active;
    }

    /**
     * Allowed transitions. Archived is terminal — no path out.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Active => [self::Suspended, self::Archived],
            self::Suspended => [self::Active, self::Archived],
            self::Archived => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), strict: true);
    }
}
