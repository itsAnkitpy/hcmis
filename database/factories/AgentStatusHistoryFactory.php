<?php

namespace Database\Factories;

use App\Enums\PresenceStatus;
use App\Enums\StintEndedVia;
use App\Models\AgentStatusHistory;
use App\Models\BreakCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentStatusHistory>
 *
 * tenant_id is stamped from the active TenantContext — wrap calls in run(). The
 * default is an OPEN Ready stint that started just now for a fresh user in the
 * same context.
 */
class AgentStatusHistoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => PresenceStatus::Ready,
            'started_at' => now(),
            'ended_at' => null,
            'ended_via' => null,
        ];
    }

    /**
     * A stint for a specific agent.
     */
    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes): array => ['user_id' => $user->id]);
    }

    public function status(PresenceStatus $status): static
    {
        return $this->state(fn (array $attributes): array => ['status' => $status]);
    }

    /**
     * A closed stint (ended by the next status change).
     */
    public function ended(): static
    {
        return $this->state(fn (array $attributes): array => [
            'ended_at' => now(),
            'ended_via' => StintEndedVia::Changed,
        ]);
    }

    /**
     * A break stint under a category, snapshotting its limit as BK-2 requires.
     */
    public function onBreak(BreakCategory $category): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PresenceStatus::OnBreak,
            'break_category_id' => $category->id,
            'limit_minutes' => $category->time_limit_minutes,
        ]);
    }
}
