<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\PhoneNumber;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PhoneNumber>
 *
 * tenant_id is stamped from the active TenantContext — wrap calls in run().
 * Defaults to a live number with no campaign; use forCampaign() / inactive().
 */
class PhoneNumberFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // E.164, unique per run. Not US-shaped on purpose: nothing may assume
            // a country or a length (D-004 — production numbers will be +91...).
            'number' => '+'.fake()->unique()->numerify('###########'),
            'campaign_id' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => false]);
    }

    public function forCampaign(Campaign $campaign): static
    {
        return $this->state(fn (array $attributes): array => ['campaign_id' => $campaign->id]);
    }
}
