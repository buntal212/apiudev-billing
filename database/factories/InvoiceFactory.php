<?php

namespace Database\Factories;

use App\Models\ApplicationCustomer;
use App\Models\Invoice;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_number' => 'INV-'.fake()->unique()->bothify('########-??????'),
            'application_customer_id' => ApplicationCustomer::factory(),
            'subscription_plan_id' => function (array $attributes): int {
                $customer = ApplicationCustomer::query()->findOrFail($attributes['application_customer_id']);

                return SubscriptionPlan::factory()->create([
                    'application_id' => $customer->application_id,
                ])->id;
            },
            'subscription_id' => null,
            'amount' => '100000.00',
            'currency' => 'IDR',
            'duration_months' => 1,
            'duration_days' => 30,
            'idempotency_key' => fake()->unique()->uuid(),
            'status' => Invoice::StatusPending,
            'issued_at' => now(),
            'due_at' => now()->addDays(7),
            'paid_at' => null,
            'cancelled_at' => null,
            'metadata' => null,
        ];
    }
}
