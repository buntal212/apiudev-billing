<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationCustomer;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\PaymentAllocation;
use App\Models\PaymentAttempt;
use App\Models\ReferralCode;
use App\Models\ReferralCommissionLedger;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\ReferralCodeGenerator;
use App\Services\ReferralCommissionService;
use App\Services\ReferralValidationService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PartnerReferralApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_can_create_update_and_soft_delete_partner_with_audit(): void
    {
        Sanctum::actingAs($this->admin(), ['admin:access']);
        $payload = ['name' => 'Mitra UDEV', 'whatsapp_number' => '628123456789', 'email' => 'mitra@udev.test', 'is_active' => true, 'notes' => 'Catatan awal'];

        $partnerId = $this->postJson('/api/v1/admin/partners', $payload)->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/admin/partners/{$partnerId}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->deleteJson("/api/v1/admin/partners/{$partnerId}")->assertNoContent();

        $this->assertSoftDeleted('partners', ['id' => $partnerId]);
        $this->assertDatabaseCount('partner_audits', 3);
    }

    public function test_referral_admin_endpoint_requires_admin_access(): void
    {
        $this->getJson('/api/v1/admin/referral-codes')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create(), ['admin:access']);
        $this->getJson('/api/v1/admin/referral-codes')->assertForbidden();
    }

    public function test_referral_code_is_generated_without_code_field_and_uses_correct_price(): void
    {
        $partner = Partner::factory()->create();
        Sanctum::actingAs($this->admin(), ['admin:access']);

        $firstCode = $this->postJson('/api/v1/admin/referral-codes', ['partner_id' => $partner->id, 'type' => 'umum', 'is_active' => true])
            ->assertCreated()->assertJsonPath('data.discount_amount', '50000.00')->assertJsonPath('data.customer_price', '125000.00')->json('data.code');
        $secondCode = $this->postJson('/api/v1/admin/referral-codes', ['partner_id' => $partner->id, 'type' => 'khusus', 'is_active' => true])
            ->assertCreated()->assertJsonPath('data.discount_amount', '75000.00')->assertJsonPath('data.customer_price', '100000.00');
        $this->assertMatchesRegularExpression('/^REF-[A-Z0-9]{6}$/', $firstCode);
        $this->assertNotSame($firstCode, $secondCode->json('data.code'));
    }

    public function test_request_code_is_ignored_and_generated_code_does_not_change_on_update(): void
    {
        $partner = Partner::factory()->create();
        Sanctum::actingAs($this->admin(), ['admin:access']);

        $created = $this->postJson('/api/v1/admin/referral-codes', ['partner_id' => $partner->id, 'code' => 'REF-MANUAL', 'type' => 'umum', 'is_active' => true])->assertCreated()->json('data');
        $this->assertNotSame('REF-MANUAL', $created['code']);
        $this->patchJson("/api/v1/admin/referral-codes/{$created['id']}", ['code' => 'REF-DIUBAH', 'is_active' => false])
            ->assertOk()->assertJsonPath('data.code', $created['code'])->assertJsonPath('data.is_active', false);
    }

    public function test_generator_retries_when_a_generated_code_collides(): void
    {
        $partner = Partner::factory()->create();
        ReferralCode::factory()->create(['partner_id' => $partner->id, 'code' => 'REF-BENTUR']);
        app()->instance(ReferralCodeGenerator::class, new class(['REF-BENTUR', 'REF-UNIK12']) extends ReferralCodeGenerator
        {
            /** @param array<int, string> $codes */
            public function __construct(private array $codes) {}

            public function generate(): string
            {
                return array_shift($this->codes);
            }
        });
        Sanctum::actingAs($this->admin(), ['admin:access']);

        $this->postJson('/api/v1/admin/referral-codes', ['partner_id' => $partner->id, 'type' => 'umum', 'is_active' => true])
            ->assertCreated()->assertJsonPath('data.code', 'REF-UNIK12');
    }

    public function test_generator_uses_the_expected_secure_code_format(): void
    {
        $generator = app(ReferralCodeGenerator::class);
        $codes = array_map(fn (): string => $generator->generate(), range(1, 10));

        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^REF-[A-Z0-9]{6}$/', $code);
        }

        $this->assertCount(10, array_unique($codes));
    }

    public function test_referral_lock_is_permanent_and_isolated_by_application(): void
    {
        $partner = Partner::factory()->create();
        $general = ReferralCode::factory()->create(['partner_id' => $partner->id, 'code' => 'UMUM', 'type' => 'umum', 'discount_amount' => 50000, 'customer_price' => 125000]);
        $special = ReferralCode::factory()->create(['partner_id' => $partner->id, 'code' => 'KHUSUS', 'type' => 'khusus', 'discount_amount' => 75000, 'customer_price' => 100000]);
        $applicationOne = Application::factory()->create();
        $applicationTwo = Application::factory()->create();
        $customerOne = ApplicationCustomer::factory()->create(['application_id' => $applicationOne->id]);
        $customerTwo = ApplicationCustomer::factory()->create(['application_id' => $applicationTwo->id]);
        $service = app(ReferralValidationService::class);

        $firstLock = $service->lockForCustomer($customerOne, 'UMUM');
        $general->update(['is_active' => false]);
        $repeatedLock = $service->lockForCustomer($customerOne, 'KHUSUS');
        $secondLock = $service->lockForCustomer($customerTwo, 'KHUSUS');

        $this->assertSame($firstLock->id, $repeatedLock->id);
        $this->assertSame('125000.00', $repeatedLock->customer_price);
        $this->assertSame('100000.00', $secondLock->customer_price);
        $this->assertDatabaseHas('customer_referrals', ['application_id' => $applicationOne->id, 'application_customer_id' => $customerOne->id, 'referral_code' => 'UMUM']);
        $this->assertDatabaseHas('customer_referrals', ['application_id' => $applicationTwo->id, 'application_customer_id' => $customerTwo->id, 'referral_code' => 'KHUSUS']);
    }

    public function test_inactive_code_is_rejected_for_new_customer(): void
    {
        $code = ReferralCode::factory()->create(['is_active' => false]);
        $customer = ApplicationCustomer::factory()->create();

        $this->expectExceptionObject(ValidationException::withMessages(['referral_code' => ['Kode referral tidak aktif atau tidak ditemukan.']]));
        app(ReferralValidationService::class)->lockForCustomer($customer, $code->code);
    }

    public function test_verified_allocation_creates_a_single_commission_ledger(): void
    {
        $partner = Partner::factory()->create();
        $code = ReferralCode::factory()->create(['partner_id' => $partner->id]);
        $application = Application::factory()->create();
        $customer = ApplicationCustomer::factory()->create(['application_id' => $application->id]);
        $referral = app(ReferralValidationService::class)->lockForCustomer($customer, $code->code);
        $plan = SubscriptionPlan::factory()->create(['application_id' => $application->id]);
        $invoice = Invoice::factory()->create(['application_customer_id' => $customer->id, 'subscription_plan_id' => $plan->id]);
        $attempt = PaymentAttempt::factory()->create(['invoice_id' => $invoice->id, 'amount' => $invoice->amount, 'currency' => $invoice->currency]);
        $allocation = PaymentAllocation::factory()->create(['invoice_id' => $invoice->id, 'payment_attempt_id' => $attempt->id, 'amount' => $invoice->amount, 'currency' => $invoice->currency]);

        $this->assertNull(app(ReferralCommissionService::class)->recordVerifiedAllocation($allocation));
        $invoice->update(['status' => Invoice::StatusPaid, 'paid_at' => now()]);
        app(ReferralCommissionService::class)->recordVerifiedAllocation($allocation);
        app(ReferralCommissionService::class)->recordVerifiedAllocation($allocation);

        $subscription = Subscription::factory()->create(['application_customer_id' => $customer->id, 'subscription_plan_id' => $plan->id]);
        $renewalInvoice = Invoice::factory()->create(['application_customer_id' => $customer->id, 'subscription_plan_id' => $plan->id, 'subscription_id' => $subscription->id, 'status' => Invoice::StatusPaid, 'paid_at' => now()]);
        $renewalAttempt = PaymentAttempt::factory()->create(['invoice_id' => $renewalInvoice->id, 'amount' => $renewalInvoice->amount, 'currency' => $renewalInvoice->currency]);
        $renewalAllocation = PaymentAllocation::factory()->create(['invoice_id' => $renewalInvoice->id, 'payment_attempt_id' => $renewalAttempt->id, 'amount' => $renewalInvoice->amount, 'currency' => $renewalInvoice->currency]);
        app(ReferralCommissionService::class)->recordVerifiedAllocation($renewalAllocation);

        $this->assertDatabaseCount('referral_commission_ledgers', 2);
        $this->assertDatabaseHas('referral_commission_ledgers', ['customer_referral_id' => $referral->id, 'payment_allocation_id' => $allocation->id, 'commission_type' => ReferralCommissionLedger::TypeFirstPayment, 'amount' => '50000.00']);
        $this->assertDatabaseHas('referral_commission_ledgers', ['customer_referral_id' => $referral->id, 'payment_allocation_id' => $renewalAllocation->id, 'commission_type' => ReferralCommissionLedger::TypeRenewal, 'amount' => '7500.00']);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }
}
