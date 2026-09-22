<?php

namespace Database\Factories;

use App\Enums\MenuAction;
use App\Models\Menu;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Menu>
 *
 * tenant_id is stamped from the active TenantContext — wrap calls in run().
 * Defaults to a menu with a greeting and no keys; add keys with withOption().
 */
class MenuFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true).' menu',
            // A converted file's path is its kind, its client and its content hash — the
            // shape TenantMedia::pathFor produces. The client id is filled in on create,
            // so a plausible hash is enough for anything that only needs an address.
            'greeting_path' => null,
            'greeting_rights_confirmed' => true,
            'options' => [],
        ];
    }

    /** A menu with a greeting file, which is what the flow requires before it will play. */
    public function withGreeting(?string $path = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'greeting_path' => $path ?? 'menu-greeting/1/'.str_repeat('a', 64).'.wav',
        ]);
    }

    /** One key on the menu. Call it more than once to build a several-key menu. */
    public function withOption(string $key, MenuAction $action, string $label, ?string $soundPath = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'options' => [...($attributes['options'] ?? []), [
                'key' => $key,
                'label' => $label,
                'action' => $action->value,
                'sound_path' => $soundPath,
                'sound_rights_confirmed' => $soundPath !== null,
            ]],
        ]);
    }
}
