<?php

namespace Database\Factories;

use App\Models\ApplicationCustomer;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'application_customer_id' => ApplicationCustomer::factory(),
            'subscription_plan_id' => function (array $attributes): int {
                $customer = ApplicationCustomer::query()->findOrFail($attributes['application_customer_id']);

                return SubscriptionPlan::factory()->create([
                    'application_id' => $customer->application_id,
                ])->id;
            },
            'starts_at' => now()->startOfDay(),
            'expires_at' => now()->startOfDay()->addDays(30),
            'status' => Subscription::StatusActive,
        ];
    }
}
