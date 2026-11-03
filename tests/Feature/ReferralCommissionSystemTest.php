<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationCustomer;
use App\Models\ApplicationPaymentGateway;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\PaymentAllocation;
use App\Models\PaymentAttempt;
use App\Models\ReferralCode;
use App\Models\ReferralCommissionLedger;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\InvoiceSettlementService;
use App\Services\ReferralCommissionService;
use App\Services\ReferralValidationService;
use App\Services\SettlementResult;
use App\Services\VerifiedGatewayPayment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReferralCommissionSystemTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_first_payment_yields_fifty_thousand_commission(): void
    {
        [$customer, $plan, $partner] = $this->setupReferral();

        $invoice = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'amount' => '125000.00',
            'status' => Invoice::StatusPending,
        ]);

        $this->settleInvoice($invoice);

        $this->assertDatabaseHas('referral_commission_ledgers', [
            'partner_id' => $partner->id,
            'invoice_id' => $invoice->id,
            'commission_type' => ReferralCommissionLedger::TypeFirstPayment,
            'amount' => '50000.00',
            'status' => ReferralCommissionLedger::StatusAvailable,
        ]);

        $balances = app(ReferralCommissionService::class)->getPartnerBalances($partner);
        $this->assertSame('50000.00', $balances['total_earnings']);
        $this->assertSame('50000.00', $balances['available_balance']);
        $this->assertSame('0.00', $balances['pending_balance']);
    }

    public function test_subsequent_renewal_payment_yields_seven_thousand_five_hundred(): void
    {
        [$customer, $plan, $partner] = $this->setupReferral();

        // 1st Payment
        $invoice1 = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'amount' => '125000.00',
            'status' => Invoice::StatusPending,
        ]);
        $this->settleInvoice($invoice1);

        // 2nd Payment (Renewal)
        $invoice2 = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'amount' => '125000.00',
            'status' => Invoice::StatusPending,
        ]);
        $this->settleInvoice($invoice2);

        $this->assertDatabaseCount('referral_commission_ledgers', 2);
        $this->assertDatabaseHas('referral_commission_ledgers', [
            'invoice_id' => $invoice2->id,
            'commission_type' => ReferralCommissionLedger::TypeRenewal,
            'amount' => '7500.00',
            'status' => ReferralCommissionLedger::StatusAvailable,
        ]);

        $balances = app(ReferralCommissionService::class)->getPartnerBalances($partner);
        $this->assertSame('57500.00', $balances['total_earnings']);
        $this->assertSame('57500.00', $balances['available_balance']);
    }

    public function test_plan_change_subsequent_payment_yields_renewal_commission(): void
    {
        [$customer, $plan1, $partner] = $this->setupReferral();
        $plan2 = SubscriptionPlan::factory()->create(['application_id' => $customer->application_id, 'price' => '250000.00']);

        // 1st Payment with plan1
        $invoice1 = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan1->id,
            'amount' => '125000.00',
            'status' => Invoice::StatusPending,
        ]);
        $this->settleInvoice($invoice1);

        // Plan change with plan2 (subsequent payment for this customer)
        $invoice2 = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan2->id,
            'amount' => '250000.00',
            'status' => Invoice::StatusPending,
        ]);
        $this->settleInvoice($invoice2);

        $this->assertDatabaseHas('referral_commission_ledgers', [
            'invoice_id' => $invoice2->id,
            'commission_type' => ReferralCommissionLedger::TypeRenewal,
            'amount' => '7500.00',
        ]);
    }

    public function test_payment_sequence_is_calculated_per_warung_not_per_partner(): void
    {
        $partner = Partner::factory()->create();
        $code = ReferralCode::factory()->create(['partner_id' => $partner->id]);
        $app = Application::factory()->create();

        ApplicationPaymentGateway::query()->create([
            'application_id' => $app->id,
            'provider' => 'midtrans',
            'environment' => 'sandbox',
            'gateway_merchant_code' => 'SB-Mid-client-test',
            'is_active' => true,
        ]);

        $warungA = ApplicationCustomer::factory()->create(['application_id' => $app->id, 'name' => 'Warung A']);
        $warungB = ApplicationCustomer::factory()->create(['application_id' => $app->id, 'name' => 'Warung B']);

        app(ReferralValidationService::class)->lockForCustomer($warungA, $code->code);
        app(ReferralValidationService::class)->lockForCustomer($warungB, $code->code);

        $plan = SubscriptionPlan::factory()->create(['application_id' => $app->id]);

        // Warung A: 1st payment -> Rp 50,000
        $invA1 = Invoice::factory()->create(['application_customer_id' => $warungA->id, 'subscription_plan_id' => $plan->id, 'amount' => '125000.00', 'status' => Invoice::StatusPending]);
        $this->settleInvoice($invA1);

        // Warung B: 1st payment -> Must ALSO be Rp 50,000 (because it is Warung B's first payment, despite same partner)
        $invB1 = Invoice::factory()->create(['application_customer_id' => $warungB->id, 'subscription_plan_id' => $plan->id, 'amount' => '125000.00', 'status' => Invoice::StatusPending]);
        $this->settleInvoice($invB1);

        // Warung A: 2nd payment -> Rp 7,500
        $invA2 = Invoice::factory()->create(['application_customer_id' => $warungA->id, 'subscription_plan_id' => $plan->id, 'amount' => '125000.00', 'status' => Invoice::StatusPending]);
        $this->settleInvoice($invA2);

        $this->assertDatabaseHas('referral_commission_ledgers', [
            'invoice_id' => $invA1->id,
            'commission_type' => ReferralCommissionLedger::TypeFirstPayment,
            'amount' => '50000.00',
        ]);
        $this->assertDatabaseHas('referral_commission_ledgers', [
            'invoice_id' => $invB1->id,
            'commission_type' => ReferralCommissionLedger::TypeFirstPayment,
            'amount' => '50000.00',
        ]);
        $this->assertDatabaseHas('referral_commission_ledgers', [
            'invoice_id' => $invA2->id,
            'commission_type' => ReferralCommissionLedger::TypeRenewal,
            'amount' => '7500.00',
        ]);

        $balances = app(ReferralCommissionService::class)->getPartnerBalances($partner);
        // Total for partner: 50,000 + 50,000 + 7,500 = 107,500
        $this->assertSame('107500.00', $balances['total_earnings']);
        $this->assertSame('107500.00', $balances['available_balance']);
    }

    public function test_gateway_admin_fees_do_not_affect_commission_amount(): void
    {
        [$customer, $plan, $partner] = $this->setupReferral();

        // Invoice with admin fee: base package 125000, admin fee 5000, attempt total 130000
        $invoice = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'amount' => '125000.00',
            'status' => Invoice::StatusPending,
        ]);

        $attempt = PaymentAttempt::factory()->create([
            'invoice_id' => $invoice->id,
            'provider' => 'midtrans',
            'merchant_reference' => $invoice->invoice_number . '-A1',
            'gateway_merchant_code' => 'SB-Mid-client-test',
            'gateway_environment' => 'sandbox',
            'gateway_payment_id' => (string) Str::ulid(),
            'amount' => '130000.00',
            'currency' => $invoice->currency,
            'status' => PaymentAttempt::StatusSucceeded,
            'completed_at' => now(),
            'metadata' => [
                'fee_breakdown' => [
                    'fee_amount' => '5000.00',
                ],
            ],
        ]);

        $this->settleInvoice($invoice, $attempt);

        // Commission must remain strictly Rp 50,000 regardless of the 5,000 admin fee
        $this->assertDatabaseHas('referral_commission_ledgers', [
            'invoice_id' => $invoice->id,
            'amount' => '50000.00',
        ]);
    }

    public function test_duplicate_webhook_does_not_create_duplicate_commission(): void
    {
        [$customer, $plan, $partner] = $this->setupReferral();

        $invoice = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'amount' => '125000.00',
            'status' => Invoice::StatusPending,
        ]);

        // First settlement call
        $this->settleInvoice($invoice);

        // Repeated allocation recordVerifiedAllocation call (simulating duplicate webhook / replay)
        $allocation = PaymentAllocation::where('invoice_id', $invoice->id)->firstOrFail();
        $ledger2 = app(ReferralCommissionService::class)->recordVerifiedAllocation($allocation);
        $ledger3 = app(ReferralCommissionService::class)->recordVerifiedAllocation($allocation);

        $this->assertDatabaseCount('referral_commission_ledgers', 1);
        $this->assertSame('50000.00', $ledger2->amount);
        $this->assertSame('50000.00', $ledger3->amount);
    }

    public function test_commission_reversal_creates_reversal_ledger_and_audit_trail_without_deleting_history(): void
    {
        [$customer, $plan, $partner] = $this->setupReferral();
        $admin = User::factory()->create(['is_admin' => true]);

        $invoice = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'amount' => '125000.00',
            'status' => Invoice::StatusPending,
        ]);
        $this->settleInvoice($invoice);

        $originalLedger = ReferralCommissionLedger::where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame(ReferralCommissionLedger::StatusAvailable, $originalLedger->status);

        // Perform refund/reversal via Service
        $reversalLedger = app(ReferralCommissionService::class)->reverseCommission($invoice, 'Pelanggan meminta refund langganan', $admin, '127.0.0.1');

        // Reversal assertions: history is retained!
        $this->assertDatabaseCount('referral_commission_ledgers', 2);
        $this->assertSame(ReferralCommissionLedger::TypeReversal, $reversalLedger->commission_type);
        $this->assertSame('-50000.00', $reversalLedger->amount);
        $this->assertSame(ReferralCommissionLedger::StatusReversed, $reversalLedger->status);
        $this->assertSame($originalLedger->id, $reversalLedger->reversal_of_ledger_id);
        $this->assertSame('Pelanggan meminta refund langganan', $reversalLedger->notes);

        // Original ledger status is updated to reversed
        $this->assertSame(ReferralCommissionLedger::StatusReversed, $originalLedger->fresh()->status);

        // Audit trail is stored
        $this->assertDatabaseHas('partner_audits', [
            'partner_id' => $partner->id,
            'action' => 'referral_commission.reversed',
            'user_id' => $admin->id,
        ]);

        // Balances reflect reversal
        $balances = app(ReferralCommissionService::class)->getPartnerBalances($partner);
        $this->assertSame('0.00', $balances['total_earnings']);
        $this->assertSame('0.00', $balances['available_balance']);
        $this->assertSame('50000.00', $balances['total_reversed']);
    }

    public function test_admin_api_endpoints_for_referral_commissions(): void
    {
        [$customer, $plan, $partner] = $this->setupReferral();
        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin, ['admin:access']);

        $invoice = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'amount' => '125000.00',
            'status' => Invoice::StatusPending,
        ]);
        $this->settleInvoice($invoice);

        // 1. Summary endpoint
        $summaryResponse = $this->getJson('/api/v1/admin/referral-commissions/summary')
            ->assertOk()
            ->assertJsonStructure([
                'metrics' => [
                    'total_partners',
                    'total_referred_warungs',
                    'total_commissions_count',
                    'total_earnings',
                    'total_available_balance',
                ],
                'recent_commissions',
            ]);
        $this->assertSame(1, $summaryResponse->json('metrics.total_referred_warungs'));
        $this->assertSame('50000.00', $summaryResponse->json('metrics.total_earnings'));

        // 2. List commissions endpoint
        $listResponse = $this->getJson('/api/v1/admin/referral-commissions')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'commission_type', 'amount', 'status', 'partner', 'warung']]]);
        $this->assertCount(1, $listResponse->json('data'));

        // 3. Warungs list endpoint
        $warungsResponse = $this->getJson('/api/v1/admin/referral-warungs')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'warung_name', 'partner_name', 'referral_code', 'payments_count', 'commissions_total']]]);
        $this->assertSame(1, $warungsResponse->json('data.0.payments_count'));
        $this->assertSame('50000.00', $warungsResponse->json('data.0.commissions_total'));

        // 4. Reverse commission endpoint via admin API
        $ledger = ReferralCommissionLedger::where('invoice_id', $invoice->id)->firstOrFail();
        $this->postJson("/api/v1/admin/referral-commissions/{$ledger->id}/reverse", [
            'reason' => 'Chargeback oleh bank pembeli',
        ])->assertOk()->assertJsonPath('data.commission_type', ReferralCommissionLedger::TypeReversal);

        // 5. Invoice detail shows commission & reversal info
        $invoiceDetail = $this->getJson("/api/v1/admin/invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'referral_commission' => [
                        'id',
                        'commission_type',
                        'amount',
                        'status',
                        'is_reversed',
                        'reversal' => ['notes'],
                    ],
                ],
            ]);
        $this->assertTrue($invoiceDetail->json('data.referral_commission.is_reversed'));
        $this->assertSame('Chargeback oleh bank pembeli', $invoiceDetail->json('data.referral_commission.reversal.notes'));
    }

    private function setupReferral(): array
    {
        $partner = Partner::factory()->create(['name' => 'Mitra Sentosa', 'is_active' => true]);
        $code = ReferralCode::factory()->create([
            'partner_id' => $partner->id,
            'code' => 'REF-SENTOSA',
            'type' => 'umum',
            'customer_price' => '125000.00',
            'discount_amount' => '50000.00',
        ]);
        $app = Application::factory()->create();
        ApplicationPaymentGateway::query()->create([
            'application_id' => $app->id,
            'provider' => 'midtrans',
            'environment' => 'sandbox',
            'gateway_merchant_code' => 'SB-Mid-client-test',
            'is_active' => true,
        ]);
        $customer = ApplicationCustomer::factory()->create([
            'application_id' => $app->id,
            'name' => 'Warung Berkah Jaya',
            'owner_name' => 'Pak Budi',
            'phone_number' => '081298765432',
        ]);
        app(ReferralValidationService::class)->lockForCustomer($customer, $code->code);
        $plan = SubscriptionPlan::factory()->create(['application_id' => $app->id, 'price' => '175000.00']);

        return [$customer, $plan, $partner];
    }

    private function settleInvoice(Invoice $invoice, ?PaymentAttempt $attempt = null): SettlementResult
    {
        if ($attempt === null) {
            $attempt = PaymentAttempt::factory()->create([
                'invoice_id' => $invoice->id,
                'provider' => 'midtrans',
                'merchant_reference' => $invoice->invoice_number . '-A1',
                'gateway_merchant_code' => 'SB-Mid-client-test',
                'gateway_environment' => 'sandbox',
                'gateway_payment_id' => (string) Str::ulid(),
                'amount' => $invoice->amount,
                'currency' => $invoice->currency,
                'status' => PaymentAttempt::StatusSucceeded,
                'completed_at' => now(),
            ]);
        }

        $verification = VerifiedGatewayPayment::paid(
            provider: $attempt->provider,
            gatewayPaymentId: (string) $attempt->gateway_payment_id,
            merchantOrderId: (string) $attempt->merchant_reference,
            amountMinor: (int) round((float) $attempt->getRawOriginal('amount')),
            currency: $attempt->currency,
            merchantCode: (string) $attempt->gateway_merchant_code,
            environment: (string) $attempt->gateway_environment,
            paidAt: now(),
        );

        return app(InvoiceSettlementService::class)->settle($attempt, $verification);
    }
}
