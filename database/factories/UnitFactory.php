<?php

namespace Database\Factories;

use App\Enums\UnitAvailabilityStatus;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Unit>
 */
class UnitFactory extends Factory
{
    public function definition(): array
    {
        $bedrooms = fake()->numberBetween(0, 4);
        $bathrooms = fake()->randomFloat(1, 1, 3);
        $sqft = fake()->numberBetween(500, 2500);

        return [
            'property_id' => Property::factory(),
            'unit_number' => fake()->optional()->bothify('##?'),
            'internal_name' => fake()->optional()->word(),
            'bedrooms' => $bedrooms,
            'bathrooms' => $bathrooms,
            'square_feet' => $sqft,
            'rent_amount' => fake()->numberBetween(1000, 5000),
            'security_deposit' => fake()->numberBetween(1000, 5000),
            'availability_status' => UnitAvailabilityStatus::AVAILABLE,
            'available_from' => fake()->optional()->dateTimeBetween('now', '+3 months'),
            'amenities' => fake()->optional()->randomElements(
                ['Dishwasher', 'Washer/Dryer', 'Balcony', 'Parking', 'Storage'],
                fake()->numberBetween(0, 3)
            ),
        ];
    }

    public function available(): static
    {
        return $this->state(fn (array $attributes) => [
            'availability_status' => UnitAvailabilityStatus::AVAILABLE,
        ]);
    }

    public function occupied(): static
    {
        return $this->state(fn (array $attributes) => [
            'availability_status' => UnitAvailabilityStatus::OCCUPIED,
        ]);
    }
}
