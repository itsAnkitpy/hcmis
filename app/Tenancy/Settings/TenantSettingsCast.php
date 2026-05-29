<?php

namespace App\Tenancy\Settings;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * @implements CastsAttributes<TenantSettings, TenantSettings|array<string, mixed>|null>
 */
class TenantSettingsCast implements CastsAttributes
{
    /**
     * Read JSONB out of the DB into a TenantSettings DTO. A null column yields
     * the default-shaped settings so callers can always rely on the object.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): TenantSettings
    {
        if ($value === null) {
            return new TenantSettings;
        }

        $decoded = is_array($value) ? $value : json_decode((string) $value, associative: true);

        return TenantSettings::fromArray(is_array($decoded) ? $decoded : []);
    }

    /**
     * Persist a TenantSettings DTO (or an array shaped like one) back to JSONB.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, string>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        $payload = match (true) {
            $value === null => (new TenantSettings)->toArray(),
            $value instanceof TenantSettings => $value->toArray(),
            is_array($value) => TenantSettings::fromArray($value)->toArray(),
            default => (new TenantSettings)->toArray(),
        };

        return [$key => json_encode($payload, JSON_THROW_ON_ERROR)];
    }
}
