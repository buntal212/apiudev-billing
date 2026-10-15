<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Services\PaymentAttemptService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PaymentAttemptServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_repeating_a_payment_attempt_request_with_the_same_idempotency_key_returns_the_existing_attempt(): void
    {
        $invoice = Invoice::factory()->create(['amount' => '175000.00']);
        $service = app(PaymentAttemptService::class);

        $firstAttempt = $service->recordAttempt($invoice, 'DANA', 'attempt-idempotent-0001');
        $secondAttempt = $service->recordAttempt($invoice, 'DANA', 'attempt-idempotent-0001');

        $this->assertSame($firstAttempt->id, $secondAttempt->id);
        $this->assertSame(1, $firstAttempt->attempt_number);
        $this->assertSame('175000.00', $firstAttempt->amount);
        $this->assertDatabaseCount('payment_attempts', 1);
    }

    public function test_rejects_a_second_active_payment_attempt_for_the_same_invoice(): void
    {
        $invoice = Invoice::factory()->create();
        $service = app(PaymentAttemptService::class);
        $service->recordAttempt($invoice, 'DANA', 'attempt-active-0001');

        try {
            $service->recordAttempt($invoice, 'DANA', 'attempt-active-0002');

            self::fail('Percobaan pembayaran aktif kedua seharusnya ditolak.');
        } catch (ValidationException $exception) {
            self::assertSame(
                'Invoice sudah memiliki percobaan pembayaran aktif.',
                $exception->errors()['invoice'][0],
            );
        }
    }

    public function test_rejects_payment_attempts_for_a_finalized_invoice(): void
    {
        $invoice = Invoice::factory()->create(['status' => Invoice::StatusPaid]);

        try {
            app(PaymentAttemptService::class)->recordAttempt(
                $invoice,
                'DANA',
                'attempt-paid-invoice',
            );

            self::fail('Invoice lunas tidak boleh menerima percobaan pembayaran baru.');
        } catch (ValidationException $exception) {
            self::assertSame(
                'Invoice dengan status saat ini tidak dapat menerima percobaan pembayaran.',
                $exception->errors()['invoice'][0],
            );
        }
    }

    public function test_rejects_an_invalid_payment_attempt_status_transition(): void
    {
        $paymentAttempt = PaymentAttempt::factory()->create([
            'status' => PaymentAttempt::StatusSucceeded,
        ]);

        try {
            app(PaymentAttemptService::class)->transitionStatus(
                $paymentAttempt,
                PaymentAttempt::StatusPending,
            );

            self::fail('Perubahan status dari selesai ke menunggu seharusnya ditolak.');
        } catch (ValidationException $exception) {
            self::assertSame(
                'Perubahan status percobaan pembayaran tidak valid.',
                $exception->errors()['status'][0],
            );
        }
    }

    public function test_a_successful_payment_attempt_does_not_automatically_mark_the_invoice_as_paid(): void
    {
        $invoice = Invoice::factory()->create();
        $service = app(PaymentAttemptService::class);
        $paymentAttempt = $service->recordAttempt($invoice, 'DANA', 'attempt-transition-0001');

        $service->transitionStatus($paymentAttempt, PaymentAttempt::StatusPending);
        $completedAttempt = $service->transitionStatus(
            $paymentAttempt->refresh(),
            PaymentAttempt::StatusSucceeded,
            'DANA-REFERENCE-0001',
        );
        $invoice->refresh();

        $this->assertSame(PaymentAttempt::StatusSucceeded, $completedAttempt->status);
        $this->assertSame(Invoice::StatusPending, $invoice->status);
        $this->assertNotNull($completedAttempt->completed_at);
    }

    public function test_invoice_and_payment_attempt_relationships_are_available(): void
    {
        $invoice = Invoice::factory()->create();
        $paymentAttempt = PaymentAttempt::factory()->create(['invoice_id' => $invoice->id]);

        $this->assertSame($invoice->id, $paymentAttempt->invoice->id);
        $this->assertSame($paymentAttempt->id, $invoice->paymentAttempts->sole()->id);
    }

    public function test_invoice_cannot_be_deleted_when_a_payment_attempt_exists(): void
    {
        $invoice = Invoice::factory()->create();
        PaymentAttempt::factory()->create(['invoice_id' => $invoice->id]);

        $this->expectException(QueryException::class);

        $invoice->delete();
    }

    public function test_provider_reference_is_unique_within_a_provider_when_present(): void
    {
        PaymentAttempt::factory()->create([
            'provider' => 'DANA',
            'provider_reference' => 'DANA-REFERENCE-UNIQUE',
        ]);

        $this->expectException(QueryException::class);

        PaymentAttempt::factory()->create([
            'provider' => 'DANA',
            'provider_reference' => 'DANA-REFERENCE-UNIQUE',
        ]);
    }
}
