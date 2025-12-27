<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Feature>
 */
class FeatureFactory extends Factory
{
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->word(),
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'requires_identity_verification' => fake()->boolean(),
            'dependent_features' => null,
            'enabled_by_default' => false,
        ];
    }

    public function requiresVerification(): static
    {
        return $this->state(fn (array $attributes) => [
            'requires_identity_verification' => true,
        ]);
    }

    public function enabledByDefault(): static
    {
        return $this->state(fn (array $attributes) => [
            'enabled_by_default' => true,
        ]);
    }
}
