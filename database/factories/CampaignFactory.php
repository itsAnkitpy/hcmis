<?php

namespace Database\Factories;

use App\Enums\CampaignTemplate;
use App\Models\Campaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Campaign>
 *
 * tenant_id is intentionally NOT set here — BelongsToTenant stamps it from the
 * active TenantContext, so wrap factory calls in TenantContext::run($tenantId).
 */
class CampaignFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => ucwords(fake()->unique()->words(2, true)).' Campaign',
            'template' => fake()->randomElement(CampaignTemplate::cases()),
            'is_active' => true,
            'custom_fields' => [],
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => false]);
    }

    public function template(CampaignTemplate $template): static
    {
        return $this->state(fn (array $attributes): array => ['template' => $template]);
    }
}
