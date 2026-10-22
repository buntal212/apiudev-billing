<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\SettlementEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SettlementEvent>
 */
class SettlementEventFactory extends Factory
{
    protected $model = SettlementEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $invoice = Invoice::factory()->create();
        $attempt = PaymentAttempt::factory()->create(['invoice_id' => $invoice->id]);

        return [
            'invoice_id' => $invoice->id,
            'payment_attempt_id' => $attempt->id,
            'event_type' => SettlementEvent::EventSettlement,
            'status' => SettlementEvent::StatusCompleted,
            'idempotency_key' => fake()->unique()->uuid(),
            'error_code' => null,
            'processed_at' => now(),
        ];
    }
}
