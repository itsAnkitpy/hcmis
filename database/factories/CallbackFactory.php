<?php

namespace Database\Factories;

use App\Enums\CallbackStatus;
use App\Models\Callback;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Callback>
 *
 * tenant_id is stamped from the active TenantContext — wrap calls in run().
 * The default makes a lead in the SAME context and derives campaign_id from it,
 * so the two FKs always agree. owner_agent_id defaults null (pooled-shaped);
 * pass forAgent() for the sticky v1 row.
 */
class CallbackFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $lead = Lead::factory()->create();

        return [
            'lead_id' => $lead->id,
            'campaign_id' => $lead->campaign_id,
            'scheduled_at' => fake()->dateTimeBetween('now', '+7 days'),
            'owner_agent_id' => null,
            'status' => CallbackStatus::Pending,
            'notes' => null,
        ];
    }

    /**
     * Bind the callback to an existing lead, keeping campaign_id consistent.
     */
    public function forLead(Lead $lead): static
    {
        return $this->state(fn (array $attributes): array => [
            'lead_id' => $lead->id,
            'campaign_id' => $lead->campaign_id,
        ]);
    }

    /**
     * The sticky v1 shape: owned by one agent.
     */
    public function forAgent(User $agent): static
    {
        return $this->state(fn (array $attributes): array => ['owner_agent_id' => $agent->id]);
    }

    public function status(CallbackStatus $status): static
    {
        return $this->state(fn (array $attributes): array => ['status' => $status]);
    }

    /**
     * Scheduled in the past — i.e. due now.
     */
    public function due(): static
    {
        return $this->state(fn (array $attributes): array => ['scheduled_at' => now()->subHour()]);
    }

    /**
     * Scheduled in the future — i.e. not yet due.
     */
    public function notYetDue(): static
    {
        return $this->state(fn (array $attributes): array => ['scheduled_at' => now()->addDay()]);
    }
}
