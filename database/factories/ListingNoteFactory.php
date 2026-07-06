<?php

namespace Database\Factories;

use App\Models\Admin;
use App\Models\Listing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ListingNote>
 */
class ListingNoteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'listing_id' => Listing::factory(),
            'admin_id' => Admin::factory(),
            'body' => fake()->paragraph(),
        ];
    }
}
