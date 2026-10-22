<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\PaymentAllocation;
use App\Models\PaymentAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentAllocation>
 */
class PaymentAllocationFactory extends Factory
{
    protected $model = PaymentAllocation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $invoice = Invoice::factory()->create();
        $attempt = PaymentAttempt::factory()->create([
            'invoice_id' => $invoice->id,
            'amount' => $invoice->amount,
            'currency' => $invoice->currency,
        ]);

        return [
            'invoice_id' => $invoice->id,
            'payment_attempt_id' => $attempt->id,
            'amount' => $invoice->amount,
            'currency' => $invoice->currency,
            'allocated_at' => now(),
            'idempotency_key' => fake()->unique()->uuid(),
        ];
    }
}
