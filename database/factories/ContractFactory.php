<?php

namespace Database\Factories;

use App\Enums\BillingCycle;
use App\Enums\ContractStatus;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Contract>
 */
class ContractFactory extends Factory
{
    public function definition(): array
    {
        $startDate = fake()->dateTimeBetween('now', '+1 month');
        $endDate = fake()->optional()->dateTimeBetween($startDate, '+2 years');

        return [
            'listing_id' => Listing::factory(),
            'landlord_id' => User::factory()->landlord(),
            'tenant_id' => User::factory()->tenant(),
            'rent_amount' => fake()->numberBetween(100000, 500000), // $1000-$5000 in cents
            'currency' => 'USD',
            'billing_cycle' => BillingCycle::MONTHLY,
            'payment_day' => fake()->numberBetween(1, 28),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'status' => ContractStatus::DRAFT,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ContractStatus::DRAFT,
        ]);
    }

    public function pendingTenant(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ContractStatus::PENDING_TENANT,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ContractStatus::ACTIVE,
        ]);
    }

    public function terminated(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ContractStatus::TERMINATED,
            'terminated_by' => fake()->randomElement(['landlord', 'tenant', 'admin']),
            'termination_reason' => fake()->sentence(),
        ]);
    }
}
