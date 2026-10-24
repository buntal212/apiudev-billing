<?php

namespace Tests\Feature;

use App\Models\ApplicationCustomer;
use App\Models\ApplicationPaymentGateway;
use App\Models\Invoice;
use App\Models\PaymentAllocation;
use App\Models\PaymentAttempt;
use App\Models\SettlementEvent;
use App\Models\Subscription;
use App\Services\InvoiceSettlementService;
use App\Services\SettlementRejectedException;
use App\Services\VerifiedGatewayPayment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InvoiceSettlementServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_payment_is_allocated_and_subscription_is_renewed_once(): void
    {
        [$invoice, $attempt, $subscription] = $this->settleableInvoice(withSubscription: true);
        $previousExpiry = $subscription->expires_at->toImmutable();
        $verification = $this->verification($attempt);

        $first = app(InvoiceSettlementService::class)->settle($attempt, $verification);
        $second = app(InvoiceSettlementService::class)->settle($attempt->refresh(), $verification);

        $this->assertFalse($first->alreadySettled);
        $this->assertTrue($second->alreadySettled);
        $this->assertSame(Invoice::StatusPaid, $invoice->fresh()->status);
        $this->assertTrue($subscription->fresh()->expires_at->equalTo($previousExpiry->addDays(30)));
        $this->assertDatabaseCount('payment_allocations', 1);
        $this->assertDatabaseCount('subscription_period_events', 1);
        $this->assertDatabaseHas('settlement_events', [
            'payment_attempt_id' => $attempt->id,
            'status' => SettlementEvent::StatusCompleted,
            'error_code' => null,
        ]);
    }

    public function test_verified_payment_activates_a_new_subscription_when_invoice_has_none(): void
    {
        [$invoice, $attempt] = $this->settleableInvoice();
        $paidAt = now()->subMinutes(2);

        $result = app(InvoiceSettlementService::class)->settle(
            $attempt,
            $this->verification($attempt, paidAt: $paidAt),
        );
        $invoice->refresh();

        $this->assertFalse($result->alreadySettled);
        $this->assertNotNull($invoice->subscription_id);
        $subscription = Subscription::query()->findOrFail($invoice->subscription_id);
        $this->assertSame($paidAt->format('Y-m-d H:i:s'), $subscription->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame($paidAt->addDays(30)->format('Y-m-d H:i:s'), $subscription->expires_at->format('Y-m-d H:i:s'));
        $this->assertSame(Subscription::StatusActive, $subscription->status);
        $this->assertDatabaseHas('subscription_period_events', [
            'subscription_id' => $subscription->id,
            'event_type' => 'activation',
            'idempotency_key' => 'settlement:payment-attempt:'.$attempt->id.':activation',
        ]);

        app(InvoiceSettlementService::class)->settle(
            $attempt->refresh(),
            $this->verification($attempt, paidAt: $paidAt),
        );

        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseCount('subscription_period_events', 1);
    }

    public function test_paid_invoice_without_subscription_can_be_recovered_idempotently(): void
    {
        [$invoice, $attempt] = $this->settleableInvoice();
        $settlementKey = 'settlement:payment-attempt:'.$attempt->id;
        $paidAt = now()->subMinutes(3);

        PaymentAllocation::query()->create([
            'invoice_id' => $invoice->id,
            'payment_attempt_id' => $attempt->id,
            'amount' => $invoice->amount,
            'currency' => $invoice->currency,
            'allocated_at' => $paidAt,
            'idempotency_key' => $settlementKey,
        ]);
        $invoice->update([
            'status' => Invoice::StatusPaid,
            'paid_at' => $paidAt,
        ]);
        SettlementEvent::query()->create([
            'invoice_id' => $invoice->id,
            'payment_attempt_id' => $attempt->id,
            'event_type' => SettlementEvent::EventSettlement,
            'status' => SettlementEvent::StatusCompleted,
            'idempotency_key' => $settlementKey,
            'processed_at' => $paidAt,
        ]);

        $first = app(InvoiceSettlementService::class)->recoverSubscription($invoice);
        $second = app(InvoiceSettlementService::class)->recoverSubscription($invoice->refresh());

        $this->assertNotNull($first->subscription_id);
        $this->assertSame($first->subscription_id, $second->subscription_id);
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseCount('subscription_period_events', 1);
    }

    public function test_unpaid_invoice_cannot_recover_subscription(): void
    {
        [$invoice] = $this->settleableInvoice();

        $this->expectException(ValidationException::class);
        app(InvoiceSettlementService::class)->recoverSubscription($invoice);

        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_pending_expired_and_failed_attempts_cannot_be_settled(): void
    {
        foreach ([
            PaymentAttempt::StatusPending,
            PaymentAttempt::StatusExpired,
            PaymentAttempt::StatusFailed,
        ] as $status) {
            [$invoice, $attempt] = $this->settleableInvoice();
            $attempt->update(['status' => $status]);

            try {
                app(InvoiceSettlementService::class)->settle($attempt, $this->verification($attempt));
                self::fail('Attempt non-paid seharusnya ditolak.');
            } catch (SettlementRejectedException $exception) {
                self::assertSame('payment_not_verified', $exception->safeCode);
            }

            $this->assertSame(Invoice::StatusPending, $invoice->fresh()->status);
            $this->assertDatabaseHas('settlement_events', [
                'payment_attempt_id' => $attempt->id,
                'status' => SettlementEvent::StatusRejected,
                'error_code' => 'payment_not_verified',
            ]);
        }
    }

    public function test_expired_or_cancelled_invoice_cannot_be_settled(): void
    {
        foreach ([Invoice::StatusExpired, Invoice::StatusCancelled] as $invoiceStatus) {
            [$invoice, $attempt] = $this->settleableInvoice(['status' => $invoiceStatus]);

            try {
                app(InvoiceSettlementService::class)->settle($attempt, $this->verification($attempt));
                self::fail('Invoice non-pending seharusnya ditolak.');
            } catch (SettlementRejectedException $exception) {
                self::assertSame('invoice_not_settleable', $exception->safeCode);
            }

            $this->assertDatabaseHas('settlement_events', [
                'payment_attempt_id' => $attempt->id,
                'error_code' => 'invoice_not_settleable',
            ]);
            $this->assertSame($invoiceStatus, $invoice->fresh()->status);
        }
    }

    public function test_amount_currency_merchant_and_application_mismatches_are_rejected(): void
    {
        [$invoice, $attempt] = $this->settleableInvoice();
        $wrongAmount = VerifiedGatewayPayment::paid(
            provider: 'dana',
            gatewayPaymentId: (string) $attempt->gateway_payment_id,
            merchantOrderId: (string) $attempt->merchant_reference,
            amountMinor: 1,
            currency: 'IDR',
            merchantCode: 'merchant-sandbox-1',
            environment: 'sandbox',
            paidAt: now(),
        );

        try {
            app(InvoiceSettlementService::class)->settle($attempt, $wrongAmount);
            self::fail('Nominal yang berbeda seharusnya ditolak.');
        } catch (SettlementRejectedException $exception) {
            self::assertSame('amount_or_currency_mismatch', $exception->safeCode);
        }

        $wrongCurrency = VerifiedGatewayPayment::paid(
            provider: 'dana',
            gatewayPaymentId: (string) $attempt->gateway_payment_id,
            merchantOrderId: (string) $attempt->merchant_reference,
            amountMinor: 10000,
            currency: 'USD',
            merchantCode: 'merchant-sandbox-1',
            environment: 'sandbox',
            paidAt: now(),
        );

        try {
            app(InvoiceSettlementService::class)->settle($attempt, $wrongCurrency);
            self::fail('Currency yang berbeda seharusnya ditolak.');
        } catch (SettlementRejectedException $exception) {
            self::assertSame('amount_or_currency_mismatch', $exception->safeCode);
        }

        $other = $this->settleableInvoice();
        $foreignVerification = $this->verification($attempt, merchantCode: 'merchant-sandbox-2');

        try {
            app(InvoiceSettlementService::class)->settle($attempt, $foreignVerification);
            self::fail('Merchant yang berbeda seharusnya ditolak.');
        } catch (SettlementRejectedException $exception) {
            self::assertSame('merchant_identity_mismatch', $exception->safeCode);
        }

        $this->assertSame(Invoice::StatusPending, $invoice->fresh()->status);
        $this->assertSame(Invoice::StatusPending, $other[0]->fresh()->status);
    }

    public function test_overdue_verified_payment_is_settled_without_reopening_other_invoice_statuses(): void
    {
        [$invoice, $attempt] = $this->settleableInvoice(['due_at' => now()->subMinute()]);

        $result = app(InvoiceSettlementService::class)->settle(
            $attempt,
            $this->verification($attempt, paidAt: now()),
        );

        $this->assertFalse($result->alreadySettled);
        $this->assertSame(Invoice::StatusPaid, $invoice->fresh()->status);
        $this->assertDatabaseCount('payment_allocations', 1);
    }

    public function test_overdue_payment_extends_active_subscription_from_current_expiry(): void
    {
        [$invoice, $attempt, $subscription] = $this->settleableInvoice(
            ['due_at' => now()->subDay()],
            withSubscription: true,
        );
        $previousExpiry = $subscription->expires_at->toImmutable();
        $previousStart = $subscription->starts_at->toImmutable();

        app(InvoiceSettlementService::class)->settle(
            $attempt,
            $this->verification($attempt, paidAt: now()),
        );

        $subscription->refresh();
        $this->assertTrue($subscription->expires_at->equalTo($previousExpiry->addDays(30)));
        $this->assertTrue($subscription->starts_at->equalTo($previousStart));
        $this->assertSame(Subscription::StatusActive, $subscription->status);
    }

    public function test_expired_subscription_starts_at_verified_payment_time(): void
    {
        [$invoice, $attempt, $subscription] = $this->settleableInvoice(
            ['due_at' => now()->subDays(3)],
            withSubscription: true,
        );
        $paidAt = now()->subHours(2);
        $subscription->update([
            'status' => Subscription::StatusExpired,
            'starts_at' => now()->subMonths(2),
            'expires_at' => $paidAt->copy()->subDay(),
        ]);

        app(InvoiceSettlementService::class)->settle(
            $attempt,
            $this->verification($attempt, paidAt: $paidAt),
        );

        $subscription->refresh();
        $this->assertSame($paidAt->format('Y-m-d H:i:s'), $subscription->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame($paidAt->addDays(30)->format('Y-m-d H:i:s'), $subscription->expires_at->format('Y-m-d H:i:s'));
        $this->assertSame(Subscription::StatusActive, $subscription->status);
        $this->assertSame(Invoice::StatusPaid, $invoice->fresh()->status);
    }

    public function test_delayed_reconciliation_preserves_verified_period_even_when_it_has_already_elapsed(): void
    {
        [$invoice, $attempt, $subscription] = $this->settleableInvoice(
            ['due_at' => now()->subMonths(3)],
            withSubscription: true,
        );
        $paidAt = now()->subMonths(2);
        $subscription->update([
            'status' => Subscription::StatusExpired,
            'starts_at' => now()->subMonths(4),
            'expires_at' => $paidAt->copy()->subDay(),
        ]);

        app(InvoiceSettlementService::class)->settle(
            $attempt,
            $this->verification($attempt, paidAt: $paidAt),
        );

        $subscription->refresh();
        $this->assertSame($paidAt->format('Y-m-d H:i:s'), $subscription->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame($paidAt->addDays(30)->format('Y-m-d H:i:s'), $subscription->expires_at->format('Y-m-d H:i:s'));
        $this->assertSame(Subscription::StatusExpired, $subscription->status);
        $this->assertSame(Invoice::StatusPaid, $invoice->fresh()->status);
    }

    public function test_customer_from_another_application_cannot_be_settled(): void
    {
        [$invoice, $attempt] = $this->settleableInvoice();
        $foreignCustomer = ApplicationCustomer::factory()->create();
        $invoice->update(['application_customer_id' => $foreignCustomer->id]);

        try {
            app(InvoiceSettlementService::class)->settle($attempt, $this->verification($attempt));
            self::fail('Customer lintas aplikasi seharusnya ditolak.');
        } catch (SettlementRejectedException $exception) {
            self::assertSame('application_customer_mismatch', $exception->safeCode);
        }

        $this->assertSame(Invoice::StatusPending, $invoice->fresh()->status);
        $this->assertDatabaseHas('settlement_events', [
            'payment_attempt_id' => $attempt->id,
            'error_code' => 'application_customer_mismatch',
        ]);
    }

    public function test_dana_production_environment_is_not_settled_by_this_stage(): void
    {
        [$invoice, $attempt] = $this->settleableInvoice();
        $attempt->update(['gateway_environment' => 'production']);
        ApplicationPaymentGateway::query()->create([
            'application_id' => $invoice->plan->application_id,
            'provider' => 'dana',
            'environment' => 'production',
            'gateway_merchant_code' => 'merchant-sandbox-1',
            'is_active' => true,
        ]);

        try {
            app(InvoiceSettlementService::class)->settle(
                $attempt,
                $this->verification($attempt),
            );
            self::fail('Settlement DANA Production seharusnya ditolak.');
        } catch (SettlementRejectedException $exception) {
            self::assertSame('gateway_environment_not_allowed', $exception->safeCode);
        }

        $this->assertSame(Invoice::StatusPending, $invoice->fresh()->status);
    }

    public function test_renewal_failure_rolls_back_invoice_and_allocation_but_keeps_safe_audit(): void
    {
        [$invoice, $attempt, $subscription] = $this->settleableInvoice(withSubscription: true);
        $subscription->update(['status' => Subscription::StatusCancelled]);

        try {
            app(InvoiceSettlementService::class)->settle($attempt, $this->verification($attempt));
            self::fail('Renewal untuk subscription nonaktif seharusnya gagal.');
        } catch (\Throwable) {
            // Kegagalan internal tetap diteruskan; event aman dicatat oleh service.
        }

        $this->assertSame(Invoice::StatusPending, $invoice->fresh()->status);
        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertDatabaseHas('settlement_events', [
            'payment_attempt_id' => $attempt->id,
            'status' => SettlementEvent::StatusFailed,
            'error_code' => 'settlement_processing_failed',
        ]);

        $subscription->update(['status' => Subscription::StatusActive]);
        $recovered = app(InvoiceSettlementService::class)->settle($attempt, $this->verification($attempt));

        $this->assertFalse($recovered->alreadySettled);
        $this->assertSame(Invoice::StatusPaid, $invoice->fresh()->status);
        $this->assertDatabaseHas('settlement_events', [
            'payment_attempt_id' => $attempt->id,
            'status' => SettlementEvent::StatusCompleted,
            'error_code' => null,
        ]);
    }

    public function test_database_constraints_prevent_two_allocations_for_one_invoice_or_attempt(): void
    {
        [$invoice, $attempt] = $this->settleableInvoice();
        PaymentAllocation::query()->create([
            'invoice_id' => $invoice->id,
            'payment_attempt_id' => $attempt->id,
            'amount' => $invoice->amount,
            'currency' => $invoice->currency,
            'allocated_at' => now(),
            'idempotency_key' => 'allocation-constraint-1',
        ]);

        $secondAttempt = PaymentAttempt::factory()->create([
            'invoice_id' => $invoice->id,
            'attempt_number' => 2,
            'status' => PaymentAttempt::StatusSucceeded,
            'amount' => $invoice->amount,
            'currency' => $invoice->currency,
        ]);

        $this->expectException(QueryException::class);
        PaymentAllocation::query()->create([
            'invoice_id' => $invoice->id,
            'payment_attempt_id' => $secondAttempt->id,
            'amount' => $invoice->amount,
            'currency' => $invoice->currency,
            'allocated_at' => now(),
            'idempotency_key' => 'allocation-constraint-2',
        ]);
    }

    /** @param array<string, mixed> $invoiceAttributes
     * @return array{0: Invoice, 1: PaymentAttempt, 2: Subscription|null}
     */
    private function settleableInvoice(array $invoiceAttributes = [], bool $withSubscription = false): array
    {
        $invoice = Invoice::factory()->create([
            'amount' => '10000.00',
            'currency' => 'IDR',
            'due_at' => now()->addDay(),
            ...$invoiceAttributes,
        ]);
        $subscription = null;

        if ($withSubscription) {
            $subscription = Subscription::factory()->create([
                'application_customer_id' => $invoice->application_customer_id,
                'subscription_plan_id' => $invoice->subscription_plan_id,
                'expires_at' => now()->addDays(30),
            ]);
            $invoice->update(['subscription_id' => $subscription->id]);
        }

        ApplicationPaymentGateway::query()->create([
            'application_id' => $invoice->plan->application_id,
            'provider' => 'dana',
            'environment' => 'sandbox',
            'gateway_merchant_code' => 'merchant-sandbox-1',
            'is_active' => true,
        ]);

        $attempt = PaymentAttempt::factory()->create([
            'invoice_id' => $invoice->id,
            'provider' => 'dana',
            'merchant_reference' => $invoice->invoice_number.'-A1',
            'gateway_merchant_code' => 'merchant-sandbox-1',
            'gateway_environment' => 'sandbox',
            'gateway_payment_id' => (string) Str::ulid(),
            'amount' => $invoice->amount,
            'currency' => $invoice->currency,
            'status' => PaymentAttempt::StatusSucceeded,
            'completed_at' => now(),
        ]);

        return [$invoice->fresh(), $attempt->fresh(), $subscription?->fresh()];
    }

    private function verification(
        PaymentAttempt $attempt,
        ?string $merchantCode = null,
        ?\DateTimeInterface $paidAt = null,
    ): VerifiedGatewayPayment {
        return VerifiedGatewayPayment::paid(
            provider: $attempt->provider,
            gatewayPaymentId: (string) $attempt->gateway_payment_id,
            merchantOrderId: (string) $attempt->merchant_reference,
            amountMinor: 10000,
            currency: 'IDR',
            merchantCode: $merchantCode ?? (string) $attempt->gateway_merchant_code,
            environment: (string) $attempt->gateway_environment,
            paidAt: $paidAt ?? now(),
        );
    }
}
