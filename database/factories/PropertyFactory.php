<?php

namespace Database\Factories;

use App\Models\User;
use App\Enums\PropertyType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Property>
 */
class PropertyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'landlord_id' => User::factory()->landlord(),
            'name' => fake()->company() . ' Apartments',
            'property_type' => fake()->randomElement(PropertyType::cases()),
            'street_address' => fake()->streetAddress(),
            'street_address_2' => fake()->optional()->secondaryAddress(),
            'city' => fake()->city(),
            'state' => fake()->stateAbbr(),
            'zip_code' => fake()->postcode(),
            'country' => 'US',
            'year_built' => fake()->optional()->numberBetween(1950, 2024),
            'lot_size' => fake()->optional()->randomFloat(2, 0.1, 5.0),
            'description' => fake()->optional()->paragraph(),
        ];
    }
}
