<?php

namespace Database\Factories;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityLog>
 *
 * tenant_id is stamped by the model's creating hook, NOT set here: wrap a call
 * in TenantContext::run($tenant->id, …) to get a tenant-owned row, or call it
 * with no context to get an ownerless/global row (the login / platform-action
 * shape). The variant RLS permits the ownerless insert (D-M7-1).
 */
class ActivityLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'log_name' => fake()->randomElement(['lead', 'campaign', 'dnc', 'rbac']),
            'description' => fake()->randomElement(['created', 'updated', 'deleted']),
            'event' => fake()->randomElement(['created', 'updated', 'deleted']),
            'properties' => [],
        ];
    }

    /**
     * A global, no-subject event the way auth events are logged — login, logout,
     * failed login. Create it with no tenant context to keep it ownerless.
     */
    public function auth(): static
    {
        return $this->state(fn (array $attributes): array => [
            'log_name' => 'auth',
            'description' => fake()->randomElement(['login', 'logout', 'failed login']),
            'event' => null,
        ]);
    }
}
