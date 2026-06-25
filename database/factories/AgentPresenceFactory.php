<?php

namespace Database\Factories;

use App\Enums\PresenceStatus;
use App\Models\AgentPresence;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentPresence>
 *
 * tenant_id is stamped from the active TenantContext — wrap calls in run(). The
 * default makes a fresh user in the SAME context and a Ready, just-seen row.
 */
class AgentPresenceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => PresenceStatus::Ready,
            'last_seen_at' => now(),
        ];
    }

    /**
     * The board row for a specific agent.
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
     * Heartbeat gone quiet — the row reads as Offline regardless of stored status
     * (PD-4). Stamped well past any sane stale window.
     */
    public function stale(): static
    {
        return $this->state(fn (array $attributes): array => ['last_seen_at' => now()->subMinutes(5)]);
    }
}
