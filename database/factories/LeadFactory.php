<?php

namespace Database\Factories;

use App\Enums\LeadStatus;
use App\Models\Campaign;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 *
 * tenant_id is stamped from the active TenantContext — wrap calls in run().
 * The default campaign_id resolves a Campaign::factory() in the SAME context,
 * so the lead and its campaign land in the same tenant. Pass forCampaign() to
 * attach to an existing campaign instead.
 */
class LeadFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'name' => fake()->name(),
            'phone' => fake()->numerify('9#########'),
            'email' => fake()->safeEmail(),
            'region' => fake()->randomElement(['North', 'South', 'East', 'West']),
            'status' => LeadStatus::New,
            'last_disposition_id' => null,
            'attempts' => 0,
            'custom_fields' => [],
        ];
    }

    public function status(LeadStatus $status): static
    {
        return $this->state(fn (array $attributes): array => ['status' => $status]);
    }

    public function forCampaign(Campaign $campaign): static
    {
        return $this->state(fn (array $attributes): array => ['campaign_id' => $campaign->id]);
    }
}
