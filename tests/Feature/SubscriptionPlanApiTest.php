<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationCustomer;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubscriptionPlanApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_can_create_read_update_and_delete_a_subscription_plan(): void
    {
        $application = Application::factory()->create();
        Sanctum::actingAs($this->admin(), ['admin:access']);

        $response = $this->postJson('/api/v1/admin/subscription-plans', [
            'application_id' => $application->id,
            'code' => 'BULANAN',
            'name' => 'Paket Bulanan',
            'description' => 'Akses selama 30 hari.',
            'duration_days' => 30,
            'price' => '125000.00',
            'currency' => 'IDR',
            'is_active' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.application_id', $application->id)
            ->assertJsonPath('data.price', '125000.00')
            ->assertJsonPath('data.duration_days', 30);

        $planId = $response->json('data.id');

        $this->getJson("/api/v1/admin/subscription-plans/{$planId}")
            ->assertOk()
            ->assertJsonPath('data.code', 'BULANAN');

        $this->patchJson("/api/v1/admin/subscription-plans/{$planId}", [
            'duration_days' => 360,
            'price' => '1200000.00',
        ])
            ->assertOk()
            ->assertJsonPath('data.duration_days', 360)
            ->assertJsonPath('data.price', '1200000.00');

        $this->deleteJson("/api/v1/admin/subscription-plans/{$planId}")
            ->assertNoContent();

        $this->assertDatabaseMissing('subscription_plans', ['id' => $planId]);
    }

    public function test_plan_code_is_unique_within_the_same_application(): void
    {
        $application = Application::factory()->create();
        $otherApplication = Application::factory()->create();
        SubscriptionPlan::factory()->create([
            'application_id' => $application->id,
            'code' => 'PREMIUM',
        ]);
        Sanctum::actingAs($this->admin(), ['admin:access']);

        $this->postJson('/api/v1/admin/subscription-plans', $this->planPayload($application, 'PREMIUM'))
            ->assertUnprocessable()
            ->assertJsonPath('errors.code.0', 'Kode paket sudah digunakan.');

        $this->postJson('/api/v1/admin/subscription-plans', $this->planPayload($otherApplication, 'PREMIUM'))
            ->assertCreated();
    }

    public function test_subscription_plan_validates_duration_and_price(): void
    {
        Sanctum::actingAs($this->admin(), ['admin:access']);

        $this->postJson('/api/v1/admin/subscription-plans', [
            'application_id' => Application::factory()->create()->id,
            'code' => 'INVALID',
            'name' => 'Paket tidak valid',
            'duration_days' => 31,
            'price' => '-1.00',
            'currency' => 'ID',
            'is_active' => true,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['duration_days', 'price', 'currency'])
            ->assertJsonPath('errors.duration_days.0', 'The Durasi paket field must be a multiple of 30.');
    }

    public function test_subscription_plans_can_be_filtered_by_application(): void
    {
        $application = Application::factory()->create();
        $otherApplication = Application::factory()->create();
        SubscriptionPlan::factory()->create(['application_id' => $application->id]);
        SubscriptionPlan::factory()->create(['application_id' => $otherApplication->id]);
        Sanctum::actingAs($this->admin(), ['admin:access']);

        $this->getJson("/api/v1/admin/subscription-plans?application_id={$application->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.application_id', $application->id);
    }

    public function test_subscription_plan_with_subscription_history_cannot_be_deleted(): void
    {
        $application = Application::factory()->create();
        $customer = ApplicationCustomer::factory()->create(['application_id' => $application->id]);
        $plan = SubscriptionPlan::factory()->create(['application_id' => $application->id]);
        Subscription::factory()->create([
            'application_customer_id' => $customer->id,
            'subscription_plan_id' => $plan->id,
        ]);
        Sanctum::actingAs($this->admin(), ['admin:access']);

        $this->deleteJson("/api/v1/admin/subscription-plans/{$plan->id}")
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.subscription_plan.0',
                'Paket langganan yang memiliki riwayat langganan tidak dapat dihapus.'
            );

        $this->assertDatabaseHas('subscription_plans', ['id' => $plan->id]);
    }

    public function test_non_admin_cannot_manage_subscription_plans(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['admin:access']);

        $this->getJson('/api/v1/admin/subscription-plans')
            ->assertForbidden()
            ->assertJsonPath('message', 'Anda tidak memiliki izin untuk mengakses halaman ini.');
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function planPayload(Application $application, string $code): array
    {
        return [
            'application_id' => $application->id,
            'code' => $code,
            'name' => 'Paket Premium',
            'duration_days' => 30,
            'price' => '100000.00',
            'currency' => 'IDR',
            'is_active' => true,
        ];
    }
}
