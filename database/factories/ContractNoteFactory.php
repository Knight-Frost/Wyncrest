<?php

namespace Database\Factories;

use App\Models\Admin;
use App\Models\Contract;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ContractNote>
 */
class ContractNoteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'contract_id' => Contract::factory(),
            'admin_id' => Admin::factory(),
            'body' => fake()->paragraph(),
        ];
    }
}
