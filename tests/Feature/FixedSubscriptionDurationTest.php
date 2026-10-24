<?php

namespace Tests\Feature;

use App\Models\ApplicationCustomer;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FixedSubscriptionDurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_activation_uses_exact_fixed_days_for_one_two_and_three_periods(): void
    {
        foreach ([30, 60, 90] as $durationDays) {
            $paidAt = now()->setDate(2025, 10, 25)->setTime(10, 0)->startOfSecond();
            $invoice = $this->invoice($durationDays);

            app(SubscriptionService::class)->activateFromVerifiedPayment(
                invoice: $invoice,
                durationDays: $durationDays,
                idempotencyKey: "fixed-duration-{$durationDays}",
                sourceType: 'test',
                verifiedPaidAt: $paidAt,
            );

            $this->assertTrue(
                $paidAt->addDays($durationDays)->equalTo($invoice->fresh()->subscription->expires_at)
            );
        }
    }

    public function test_fixed_duration_does_not_follow_month_boundaries_or_february(): void
    {
        $service = app(SubscriptionService::class);
        $monthBoundaryInvoice = $this->invoice(30);
        $monthBoundaryPaidAt = now()->setDate(2025, 1, 31)->setTime(10, 0)->startOfSecond();
        $service->activateFromVerifiedPayment(
            invoice: $monthBoundaryInvoice,
            durationDays: 30,
            idempotencyKey: 'fixed-duration-month-boundary',
            sourceType: 'test',
            verifiedPaidAt: $monthBoundaryPaidAt,
        );

        $februaryInvoice = $this->invoice(30);
        $februaryPaidAt = now()->setDate(2025, 2, 1)->setTime(10, 0)->startOfSecond();
        $service->activateFromVerifiedPayment(
            invoice: $februaryInvoice,
            durationDays: 30,
            idempotencyKey: 'fixed-duration-february',
            sourceType: 'test',
            verifiedPaidAt: $februaryPaidAt,
        );

        $this->assertTrue($monthBoundaryPaidAt->addDays(30)->equalTo($monthBoundaryInvoice->fresh()->subscription->expires_at));
        $this->assertTrue($februaryPaidAt->addDays(30)->equalTo($februaryInvoice->fresh()->subscription->expires_at));
    }

    public function test_renewal_continues_from_active_expiry_and_expired_subscription_restarts_at_paid_time(): void
    {
        $service = app(SubscriptionService::class);
        $plan = SubscriptionPlan::factory()->create();
        $customer = ApplicationCustomer::factory()->create(['application_id' => $plan->application_id]);
        $active = Subscription::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'expires_at' => now()->addDays(10),
        ]);
        $expired = Subscription::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'status' => Subscription::StatusExpired,
            'expires_at' => now()->subDay(),
        ]);
        $activeExpiry = $active->expires_at->toImmutable();
        $paidAt = now()->subDays(2)->startOfSecond();

        $service->renewFromVerifiedPayment($active, 30, 'fixed-duration-active', 'test', $paidAt);
        $service->renewFromVerifiedPayment($expired, 30, 'fixed-duration-expired', 'test', $paidAt);

        $this->assertTrue($activeExpiry->addDays(30)->equalTo($active->fresh()->expires_at));
        $this->assertTrue($paidAt->addDays(30)->equalTo($expired->fresh()->expires_at));
    }

    public function test_duration_migration_converts_existing_plan_months_to_fixed_days(): void
    {
        $migration = require database_path('migrations/2026_10_24_174239_add_fixed_duration_days_to_subscription_plans_and_invoices_table.php');
        $plan = SubscriptionPlan::factory()->create([
            'duration_months' => 3,
            'duration_days' => 90,
        ]);
        $migration->down();
        $migration->up();

        $customer = ApplicationCustomer::factory()->create(['application_id' => $plan->application_id]);
        $invoice = Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'duration_months' => 3,
            'duration_days' => 90,
        ]);

        $this->assertSame(90, (int) DB::table('subscription_plans')->where('id', $plan->id)->value('duration_days'));
        $this->assertSame(3, (int) DB::table('subscription_plans')->where('id', $plan->id)->value('duration_months'));
        $this->assertSame(90, (int) DB::table('invoices')->where('id', $invoice->id)->value('duration_days'));
        $this->assertSame(3, (int) DB::table('invoices')->where('id', $invoice->id)->value('duration_months'));
    }

    private function invoice(int $durationDays): Invoice
    {
        $plan = SubscriptionPlan::factory()->create([
            'duration_months' => intdiv($durationDays, 30),
            'duration_days' => $durationDays,
        ]);
        $customer = ApplicationCustomer::factory()->create(['application_id' => $plan->application_id]);

        return Invoice::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
            'duration_months' => intdiv($durationDays, 30),
            'duration_days' => $durationDays,
            'status' => Invoice::StatusPaid,
        ]);
    }
}
