<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionPlan>
 */
class SubscriptionPlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'application_id' => Application::factory(),
            'code' => mb_strtoupper(fake()->unique()->bothify('PLAN-####')),
            'name' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'duration_months' => 1,
            'duration_days' => 30,
            'price' => number_format(fake()->numberBetween(10_000, 5_000_000), 2, '.', ''),
            'currency' => 'IDR',
            'is_active' => true,
        ];
    }
}
