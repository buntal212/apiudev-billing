<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\PaymentAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentAttempt>
 */
class PaymentAttemptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'attempt_number' => 1,
            'provider' => 'DANA',
            'merchant_reference' => null,
            'provider_reference' => null,
            'amount' => function (array $attributes): string {
                return (string) Invoice::query()->findOrFail($attributes['invoice_id'])->amount;
            },
            'currency' => 'IDR',
            'status' => PaymentAttempt::StatusInitiated,
            'idempotency_key' => fake()->unique()->uuid(),
            'requested_at' => now(),
            'completed_at' => null,
            'failure_code' => null,
            'failure_message' => null,
            'metadata' => null,
        ];
    }
}
