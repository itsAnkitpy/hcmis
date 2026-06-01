<?php

namespace Database\Factories;

use App\Enums\ScriptType;
use App\Models\Campaign;
use App\Models\Script;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Script>
 *
 * tenant_id is stamped from the active TenantContext — wrap calls in run().
 * Defaults to a tenant-wide script (campaign_id null); use forCampaign().
 */
class ScriptFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'campaign_id' => null,
            'type' => fake()->randomElement(ScriptType::cases()),
            'content' => fake()->paragraph(),
        ];
    }

    public function type(ScriptType $type): static
    {
        return $this->state(fn (array $attributes): array => ['type' => $type]);
    }

    public function forCampaign(Campaign $campaign): static
    {
        return $this->state(fn (array $attributes): array => ['campaign_id' => $campaign->id]);
    }
}
