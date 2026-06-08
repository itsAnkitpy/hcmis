<?php

namespace Database\Factories;

use App\Enums\DncSource;
use App\Models\DncEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DncEntry>
 *
 * tenant_id is stamped from the active TenantContext — wrap calls in run().
 * The phone is normalized by the model's mutator on save (D-M6-5).
 */
class DncEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'phone' => fake()->unique()->numerify('98########'),
            'source' => fake()->randomElement(DncSource::cases()),
            'reason' => null,
            'expires_at' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'expires_at' => now()->subDay(),
        ]);
    }

    public function expiringLater(): static
    {
        return $this->state(fn (array $attributes): array => [
            'expires_at' => now()->addMonth(),
        ]);
    }
}
