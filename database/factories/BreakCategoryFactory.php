<?php

namespace Database\Factories;

use App\Models\BreakCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BreakCategory>
 *
 * tenant_id is stamped from the active TenantContext — wrap calls in run().
 * Defaults to an active category with no time limit (the seeded shape, BK-1).
 */
class BreakCategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $label = fake()->unique()->words(2, true);

        return [
            'code' => str($label)->upper()->replace(' ', '_')->value(),
            'label' => ucfirst($label),
            'time_limit_minutes' => null,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => false]);
    }

    public function withLimit(int $minutes): static
    {
        return $this->state(fn (array $attributes): array => ['time_limit_minutes' => $minutes]);
    }
}
