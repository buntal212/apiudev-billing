<?php

namespace Database\Factories;

use App\Models\Subscription;
use App\Models\SubscriptionPeriodEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionPeriodEvent>
 */
class SubscriptionPeriodEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'event_type' => 'renewal',
            'previous_expires_at' => now()->addMonth(),
            'new_expires_at' => now()->addMonths(2),
            'source_type' => 'manual',
            'source_id' => (string) fake()->uuid(),
            'idempotency_key' => (string) fake()->uuid(),
            'notes' => fake()->optional()->sentence(),
            'created_at' => now(),
        ];
    }
}
