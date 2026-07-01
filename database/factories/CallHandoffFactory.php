<?php

namespace Database\Factories;

use App\Models\CallHandoff;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CallHandoff>
 *
 * tenant_id is stamped from the active TenantContext — wrap calls in run(). The
 * default makes a fresh agent in the SAME context and a freshly-minted ticket.
 */
class CallHandoffFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agent_user_id' => User::factory(),
            'ticket' => (string) Str::uuid(),
        ];
    }

    /**
     * The handoff note waiting for a specific agent.
     */
    public function forAgent(User $agent): static
    {
        return $this->state(fn (array $attributes): array => ['agent_user_id' => $agent->id]);
    }

    /**
     * A note carrying a specific ticket (the call's UUID).
     */
    public function ticket(string $ticket): static
    {
        return $this->state(fn (array $attributes): array => ['ticket' => $ticket]);
    }
}
