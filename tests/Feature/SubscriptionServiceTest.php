<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationCustomer;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SubscriptionServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_creates_a_subscription_when_customer_and_plan_belong_to_the_same_application(): void
    {
        $application = Application::factory()->create();
        $customer = ApplicationCustomer::factory()->create(['application_id' => $application->id]);
        $plan = SubscriptionPlan::factory()->create(['application_id' => $application->id]);
        $startsAt = now()->startOfDay();
        $expiresAt = $startsAt->copy()->addDays(30);

        $subscription = app(SubscriptionService::class)->createSubscription(
            $customer,
            $plan,
            $startsAt,
            $expiresAt,
        );

        $this->assertSame($customer->id, $subscription->application_customer_id);
        $this->assertSame($plan->id, $subscription->subscription_plan_id);
        $this->assertSame(Subscription::StatusActive, $subscription->status);
        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscription->id,
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
        ]);
    }

    public function test_it_rejects_a_plan_from_a_different_application(): void
    {
        $customer = ApplicationCustomer::factory()->create();
        $plan = SubscriptionPlan::factory()->create([
            'application_id' => Application::factory()->create()->id,
        ]);

        try {
            app(SubscriptionService::class)->createSubscription(
                $customer,
                $plan,
                now()->startOfDay(),
                now()->startOfDay()->addDays(30),
            );

            self::fail('Paket dari aplikasi lain seharusnya ditolak.');
        } catch (ValidationException $exception) {
            self::assertSame(
                'Paket langganan harus berasal dari aplikasi yang sama dengan pelanggan.',
                $exception->errors()['subscription_plan_id'][0],
            );
        }
    }

    public function test_it_prevents_overlapping_active_subscriptions_for_the_same_customer(): void
    {
        $application = Application::factory()->create();
        $customer = ApplicationCustomer::factory()->create(['application_id' => $application->id]);
        $plan = SubscriptionPlan::factory()->create(['application_id' => $application->id]);
        $service = app(SubscriptionService::class);
        $startsAt = now()->startOfDay();

        $service->createSubscription($customer, $plan, $startsAt, $startsAt->copy()->addDays(30));

        try {
            $service->createSubscription(
                $customer,
                $plan,
                $startsAt->copy()->addDays(10),
                $startsAt->copy()->addDays(60),
            );

            self::fail('Langganan aktif yang tumpang tindih seharusnya ditolak.');
        } catch (ValidationException $exception) {
            self::assertSame(
                'Pelanggan sudah memiliki langganan aktif pada periode tersebut.',
                $exception->errors()['application_customer_id'][0],
            );
        }
    }

    public function test_it_allows_non_overlapping_active_subscriptions_for_the_same_customer(): void
    {
        $application = Application::factory()->create();
        $customer = ApplicationCustomer::factory()->create(['application_id' => $application->id]);
        $plan = SubscriptionPlan::factory()->create(['application_id' => $application->id]);
        $service = app(SubscriptionService::class);
        $startsAt = now()->startOfDay();
        $firstExpiresAt = $startsAt->copy()->addDays(30);

        $service->createSubscription($customer, $plan, $startsAt, $firstExpiresAt);
        $service->createSubscription($customer, $plan, $firstExpiresAt, $firstExpiresAt->copy()->addDays(30));

        $this->assertDatabaseCount('subscriptions', 2);
    }

    public function test_subscription_renewal_is_idempotent(): void
    {
        $subscription = Subscription::factory()->create([
            'expires_at' => now()->addDays(30),
        ]);
        $service = app(SubscriptionService::class);
        $idempotencyKey = 'payment-dana-0001';
        $previousExpiresAt = $subscription->expires_at->copy();

        $firstEvent = $service->extendSubscription(
            $subscription,
            30,
            $idempotencyKey,
            'payment',
            'DANA-0001',
        );
        $secondEvent = $service->extendSubscription(
            $subscription,
            30,
            $idempotencyKey,
            'payment',
            'DANA-0001',
        );

        $subscription->refresh();

        $this->assertSame($firstEvent->id, $secondEvent->id);
        $this->assertTrue($subscription->expires_at->equalTo($previousExpiresAt->addDays(30)));
        $this->assertDatabaseCount('subscription_period_events', 1);
    }

    public function test_verified_payment_renews_active_subscription_from_current_expiry(): void
    {
        $subscription = Subscription::factory()->create([
            'status' => Subscription::StatusActive,
            'expires_at' => now()->addDays(10),
        ]);
        $previousExpiry = $subscription->expires_at->toImmutable();
        $previousStart = $subscription->starts_at->toImmutable();

        app(SubscriptionService::class)->renewFromVerifiedPayment(
            subscription: $subscription,
            durationDays: 30,
            idempotencyKey: 'verified-active-renewal-0001',
            sourceType: 'payment_allocation',
            verifiedPaidAt: now(),
            sourceId: 'allocation-0001',
        );

        $subscription->refresh();
        $this->assertTrue($subscription->expires_at->equalTo($previousExpiry->addDays(30)));
        $this->assertTrue($subscription->starts_at->equalTo($previousStart));
        $this->assertSame(Subscription::StatusActive, $subscription->status);
    }

    public function test_verified_payment_restarts_expired_subscription_at_paid_at(): void
    {
        $paidAt = now()->subHours(2);
        $subscription = Subscription::factory()->create([
            'status' => Subscription::StatusExpired,
            'starts_at' => now()->subDays(60),
            'expires_at' => $paidAt->copy()->subDay(),
        ]);

        app(SubscriptionService::class)->renewFromVerifiedPayment(
            subscription: $subscription,
            durationDays: 30,
            idempotencyKey: 'verified-expired-renewal-0001',
            sourceType: 'payment_allocation',
            verifiedPaidAt: $paidAt,
            sourceId: 'allocation-0002',
        );

        $subscription->refresh();
        $this->assertSame($paidAt->format('Y-m-d H:i:s'), $subscription->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame($paidAt->addDays(30)->format('Y-m-d H:i:s'), $subscription->expires_at->format('Y-m-d H:i:s'));
        $this->assertSame(Subscription::StatusActive, $subscription->status);
    }

    public function test_verified_payment_keeps_historical_period_when_reconciliation_is_delayed(): void
    {
        $paidAt = now()->subDays(60);
        $subscription = Subscription::factory()->create([
            'status' => Subscription::StatusExpired,
            'starts_at' => now()->subDays(120),
            'expires_at' => $paidAt->copy()->subDay(),
        ]);

        app(SubscriptionService::class)->renewFromVerifiedPayment(
            subscription: $subscription,
            durationDays: 30,
            idempotencyKey: 'verified-delayed-renewal-0001',
            sourceType: 'payment_allocation',
            verifiedPaidAt: $paidAt,
            sourceId: 'allocation-0003',
        );

        $subscription->refresh();
        $this->assertSame($paidAt->format('Y-m-d H:i:s'), $subscription->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame($paidAt->addDays(30)->format('Y-m-d H:i:s'), $subscription->expires_at->format('Y-m-d H:i:s'));
        $this->assertSame(Subscription::StatusExpired, $subscription->status);
    }

    public function test_out_of_order_payment_keeps_current_active_expiry_as_anchor(): void
    {
        $subscription = Subscription::factory()->create([
            'status' => Subscription::StatusActive,
            'expires_at' => now()->addDays(10),
        ]);
        $previousExpiry = $subscription->expires_at->toImmutable();
        $paidAt = now()->subDays(2);

        $event = app(SubscriptionService::class)->renewFromVerifiedPayment(
            subscription: $subscription,
            durationDays: 30,
            idempotencyKey: 'verified-out-of-order-0001',
            sourceType: 'payment_allocation',
            verifiedPaidAt: $paidAt,
            sourceId: 'allocation-0005',
        );

        $subscription->refresh();
        $this->assertTrue($subscription->expires_at->equalTo($previousExpiry->addDays(30)));
        $this->assertStringContainsString('period_rule=continue_active_expiry', (string) $event->notes);
    }

    public function test_verified_payment_does_not_reopen_cancelled_subscription(): void
    {
        $subscription = Subscription::factory()->create([
            'status' => Subscription::StatusCancelled,
        ]);

        $this->expectException(ValidationException::class);

        app(SubscriptionService::class)->renewFromVerifiedPayment(
            subscription: $subscription,
            durationDays: 30,
            idempotencyKey: 'verified-cancelled-renewal-0001',
            sourceType: 'payment_allocation',
            verifiedPaidAt: now(),
            sourceId: 'allocation-0004',
        );
    }
}
