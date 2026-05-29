<?php

namespace App\Enums;

/**
 * Role names — the shared vocabulary across clients (M2 §5).
 *
 * Global roles operate across all clients at the reserved global team (id 0)
 * and hold no tenant membership. Per-client roles are provisioned per tenant
 * at onboarding (M3) and scoped to that tenant's team.
 *
 * Use this enum instead of bare strings (M2 carry-forward — the magic-string
 * cleanup noted in m2-identity.md §10).
 */
enum RoleName: string
{
    case SuperAdmin = 'super_admin';
    case HcAdmin = 'hc_admin';
    case OpsManager = 'ops_manager';

    case TeamLeader = 'team_leader';
    case Qc = 'qc';
    case Trainer = 'trainer';
    case Agent = 'agent';
    case ClientUser = 'client_user';

    public function isGlobal(): bool
    {
        return in_array($this, self::globals(), strict: true);
    }

    public function isPerClient(): bool
    {
        return in_array($this, self::perClient(), strict: true);
    }

    /**
     * @return array<int, self>
     */
    public static function globals(): array
    {
        return [self::SuperAdmin, self::HcAdmin, self::OpsManager];
    }

    /**
     * @return array<int, self>
     */
    public static function perClient(): array
    {
        return [self::TeamLeader, self::Qc, self::Trainer, self::Agent, self::ClientUser];
    }

    /**
     * String values of all global roles — convenience for spatie role checks
     * that still take strings (e.g. hasAnyRole()).
     *
     * @return array<int, string>
     */
    public static function globalValues(): array
    {
        return array_map(fn (self $r): string => $r->value, self::globals());
    }

    /**
     * String values of all per-client roles.
     *
     * @return array<int, string>
     */
    public static function perClientValues(): array
    {
        return array_map(fn (self $r): string => $r->value, self::perClient());
    }
}
