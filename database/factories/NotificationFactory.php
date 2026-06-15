<?php

namespace Database\Factories;

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Notification>
 */
class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => fake()->randomElement(NotificationType::cases()),
            'title' => fake()->sentence(),
            'message' => fake()->paragraph(),
            'data' => [
                'event_id' => 'test-'.fake()->uuid(),
            ],
            'read_at' => null,
            'delivered_at' => null,
            'delivery_failed_at' => null,
            'delivery_error' => null,
        ];
    }

    /**
     * Indicate the notification is unread
     */
    public function unread(): static
    {
        return $this->state(fn (array $attributes) => [
            'read_at' => null,
        ]);
    }

    /**
     * Indicate the notification is read
     */
    public function read(): static
    {
        return $this->state(fn (array $attributes) => [
            'read_at' => now(),
        ]);
    }

    /**
     * Indicate the notification is delivered
     */
    public function delivered(): static
    {
        return $this->state(fn (array $attributes) => [
            'delivered_at' => now(),
            'delivery_failed_at' => null,
            'delivery_error' => null,
        ]);
    }

    /**
     * Indicate the notification delivery failed
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'delivered_at' => null,
            'delivery_failed_at' => now(),
            'delivery_error' => 'Test failure',
        ]);
    }

    /**
     * Indicate the notification is pending delivery
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'delivered_at' => null,
            'delivery_failed_at' => null,
            'delivery_error' => null,
        ]);
    }
}
