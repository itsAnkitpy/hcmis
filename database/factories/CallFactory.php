<?php

namespace Database\Factories;

use App\Enums\CallDirection;
use App\Enums\CallOutcome;
use App\Models\Call;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Call>
 *
 * tenant_id is stamped from the active TenantContext — wrap calls in run(). The
 * default is a minimal answered outbound call with every FK null (the ad-hoc
 * shape); compose forLead() / forAgent() / the outcome+direction states as needed.
 */
class CallFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'direction' => CallDirection::Outbound,
            'from_number' => fake()->numerify('##########'),
            'to_number' => fake()->numerify('##########'),
            'lead_id' => null,
            'campaign_id' => null,
            'agent_id' => null,
            'disposition_id' => null,
            'outcome' => CallOutcome::Answered,
            'correlation_id' => null,
            'ended_at' => now(),
            'recording_disk' => null,
            'recording_path' => null,
        ];
    }

    /**
     * Bind the call to an existing lead, keeping campaign_id consistent.
     */
    public function forLead(Lead $lead): static
    {
        return $this->state(fn (array $attributes): array => [
            'lead_id' => $lead->id,
            'campaign_id' => $lead->campaign_id,
        ]);
    }

    /**
     * The agent who handled the call.
     */
    public function forAgent(User $agent): static
    {
        return $this->state(fn (array $attributes): array => ['agent_id' => $agent->id]);
    }

    public function inbound(): static
    {
        return $this->state(fn (array $attributes): array => ['direction' => CallDirection::Inbound]);
    }

    public function answered(): static
    {
        return $this->state(fn (array $attributes): array => ['outcome' => CallOutcome::Answered]);
    }

    public function noAnswer(): static
    {
        return $this->state(fn (array $attributes): array => ['outcome' => CallOutcome::NoAnswer]);
    }

    /**
     * A call with the recording already attached (CP-B3-2 enriched shape).
     */
    public function withRecording(): static
    {
        return $this->state(fn (array $attributes): array => [
            'correlation_id' => (string) Str::uuid(),
            'recording_disk' => 'recordings',
            'recording_path' => 'calls/'.fake()->uuid().'.mp3',
        ]);
    }
}
