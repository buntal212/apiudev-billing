<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationCustomer;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InvoiceServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_invoice_keeps_the_original_plan_price_and_duration_snapshot(): void
    {
        $application = Application::factory()->create();
        $customer = ApplicationCustomer::factory()->create(['application_id' => $application->id]);
        $plan = SubscriptionPlan::factory()->create([
            'application_id' => $application->id,
            'price' => '250000.00',
            'duration_months' => 1,
            'duration_days' => 30,
        ]);

        $invoice = app(InvoiceService::class)->createInvoice(
            $customer->id,
            $plan->id,
            null,
            now()->addWeek(),
            'invoice-snapshot-0001',
        );
        $plan->update(['price' => '300000.00', 'duration_months' => 1, 'duration_days' => 30]);
        $invoice->refresh();

        $this->assertSame('250000.00', $invoice->amount);
        $this->assertSame(30, $invoice->duration_days);
    }

    public function test_invoice_multiplies_a_30_day_package_for_the_selected_period_count(): void
    {
        $application = Application::factory()->create();
        $customer = ApplicationCustomer::factory()->create(['application_id' => $application->id]);
        $plan = SubscriptionPlan::factory()->create(['application_id' => $application->id, 'price' => '125000.00', 'duration_days' => 30]);

        $invoice = app(InvoiceService::class)->createInvoice($customer->id, $plan->id, null, now()->addWeek(), 'invoice-period-count-0001', 3);

        $this->assertSame('375000.00', $invoice->amount);
        $this->assertSame(3, $invoice->period_count);
        $this->assertSame(90, $invoice->duration_days);
        $this->assertSame(3, $invoice->metadata['period_count']);
    }

    public function test_rejects_a_subscription_that_does_not_match_the_selected_customer_and_plan(): void
    {
        $application = Application::factory()->create();
        $customer = ApplicationCustomer::factory()->create(['application_id' => $application->id]);
        $plan = SubscriptionPlan::factory()->create(['application_id' => $application->id]);
        $otherSubscription = Subscription::factory()->create();

        try {
            app(InvoiceService::class)->createInvoice(
                $customer->id,
                $plan->id,
                $otherSubscription->id,
                now()->addWeek(),
                'invoice-subscription-mismatch',
            );

            self::fail('Langganan yang tidak sesuai seharusnya ditolak.');
        } catch (ValidationException $exception) {
            self::assertSame(
                'Langganan harus sesuai dengan pelanggan yang dipilih.',
                $exception->errors()['subscription_id'][0],
            );
        }
    }

    public function test_rejects_a_due_date_that_is_not_after_the_invoice_issuance_time(): void
    {
        $application = Application::factory()->create();
        $customer = ApplicationCustomer::factory()->create(['application_id' => $application->id]);
        $plan = SubscriptionPlan::factory()->create(['application_id' => $application->id]);

        try {
            app(InvoiceService::class)->createInvoice(
                $customer->id,
                $plan->id,
                null,
                now(),
                'invoice-due-date-invalid',
            );

            self::fail('Tanggal jatuh tempo yang telah berlalu seharusnya ditolak.');
        } catch (ValidationException $exception) {
            self::assertSame(
                'Tanggal jatuh tempo harus setelah waktu penerbitan invoice.',
                $exception->errors()['due_at'][0],
            );
        }
    }
}
