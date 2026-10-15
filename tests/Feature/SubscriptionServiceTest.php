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
        $expiresAt = $startsAt->copy()->addMonth();

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
                now()->startOfDay()->addMonth(),
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

        $service->createSubscription($customer, $plan, $startsAt, $startsAt->copy()->addMonth());

        try {
            $service->createSubscription(
                $customer,
                $plan,
                $startsAt->copy()->addDays(10),
                $startsAt->copy()->addMonths(2),
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
        $firstExpiresAt = $startsAt->copy()->addMonth();

        $service->createSubscription($customer, $plan, $startsAt, $firstExpiresAt);
        $service->createSubscription($customer, $plan, $firstExpiresAt, $firstExpiresAt->copy()->addMonth());

        $this->assertDatabaseCount('subscriptions', 2);
    }

    public function test_subscription_renewal_is_idempotent(): void
    {
        $subscription = Subscription::factory()->create([
            'expires_at' => now()->addMonth(),
        ]);
        $service = app(SubscriptionService::class);
        $idempotencyKey = 'payment-dana-0001';
        $previousExpiresAt = $subscription->expires_at->copy();

        $firstEvent = $service->extendSubscription(
            $subscription,
            1,
            $idempotencyKey,
            'payment',
            'DANA-0001',
        );
        $secondEvent = $service->extendSubscription(
            $subscription,
            1,
            $idempotencyKey,
            'payment',
            'DANA-0001',
        );

        $subscription->refresh();

        $this->assertSame($firstEvent->id, $secondEvent->id);
        $this->assertTrue($subscription->expires_at->equalTo($previousExpiresAt->addMonth()));
        $this->assertDatabaseCount('subscription_period_events', 1);
    }
}
