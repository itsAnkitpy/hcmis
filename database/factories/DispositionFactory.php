<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\Disposition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Disposition>
 *
 * tenant_id is stamped from the active TenantContext — wrap calls in run().
 * Defaults to a tenant-wide disposition (campaign_id null); use forCampaign().
 */
class DispositionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $label = fake()->unique()->words(2, true);

        return [
            'campaign_id' => null,
            'code' => str($label)->upper()->replace(' ', '_')->value(),
            'label' => ucfirst($label),
            'is_contact' => true,
            'is_sale' => false,
            'sort_order' => 0,
        ];
    }

    public function sale(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_sale' => true,
            'is_contact' => true,
        ]);
    }

    public function forCampaign(Campaign $campaign): static
    {
        return $this->state(fn (array $attributes): array => ['campaign_id' => $campaign->id]);
    }
}
