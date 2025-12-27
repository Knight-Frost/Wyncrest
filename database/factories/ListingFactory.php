<?php

namespace Database\Factories;

use App\Models\Unit;
use App\Models\User;
use App\Enums\ListingStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Listing>
 */
class ListingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'unit_id' => Unit::factory(),
            'landlord_id' => User::factory()->landlord(),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraphs(3, true),
            'status' => ListingStatus::DRAFT,
            'pets_allowed' => fake()->boolean(),
            'pet_policy' => fake()->optional()->sentence(),
            'lease_duration_months' => fake()->optional()->randomElement([6, 12, 24]),
            'move_in_date' => fake()->optional()->dateTimeBetween('now', '+2 months'),
            'featured' => false,
            'view_count' => 0,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ListingStatus::DRAFT,
        ]);
    }

    public function pendingReview(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ListingStatus::PENDING_REVIEW,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ListingStatus::ACTIVE,
            'published_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ListingStatus::REJECTED,
            'rejection_reason' => fake()->sentence(),
        ]);
    }

    public function featured(): static
    {
        return $this->state(fn (array $attributes) => [
            'featured' => true,
            'status' => ListingStatus::ACTIVE,
            'published_at' => now(),
        ]);
    }
}
