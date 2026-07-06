<?php

namespace Database\Factories;

use App\Enums\LedgerStatus;
use App\Enums\LedgerType;
use App\Models\Contract;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\LedgerEntry>
 */
class LedgerEntryFactory extends Factory
{
    public function definition(): array
    {
        $contract = Contract::factory()->active();
        $billingStart = Carbon::parse(fake()->dateTimeBetween('-1 month', '+1 month'));
        $billingEnd = $billingStart->copy()->addMonth()->subDay();
        $dueDate = $billingStart->copy()->addDays(rand(1, 28));

        return [
            'contract_id' => $contract,
            'tenant_id' => function (array $attributes) {
                return Contract::find($attributes['contract_id'])->tenant_id;
            },
            'landlord_id' => function (array $attributes) {
                return Contract::find($attributes['contract_id'])->landlord_id;
            },
            'type' => LedgerType::RENT,
            'amount_cents' => fake()->numberBetween(100000, 500000), // $1000-$5000
            'currency' => 'USD',
            'billing_period_start' => $billingStart,
            'billing_period_end' => $billingEnd,
            'due_date' => $dueDate,
            'status' => LedgerStatus::PENDING,
        ];
    }

    public function rent(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => LedgerType::RENT,
        ]);
    }

    public function lateFee(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => LedgerType::LATE_FEE,
            'amount_cents' => fake()->numberBetween(5000, 20000), // $50-$200
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => LedgerStatus::PENDING,
            // why: definition()'s due_date is random and can land in the
            // past, which silently makes a "pending" entry read as overdue
            // too (PENDING + due_date < today) and flakes any assertion
            // that expects pending/overdue to be disjoint. Pin a safe
            // future date here; callers needing a specific date still
            // override it via create([...]).
            'due_date' => Carbon::now()->addDays(15),
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => LedgerStatus::PAID,
        ]);
    }

    public function overdue(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => LedgerStatus::OVERDUE,
            'due_date' => fake()->dateTimeBetween('-30 days', '-1 day'),
        ]);
    }

    public function waived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => LedgerStatus::WAIVED,
        ]);
    }
}
