<?php

namespace Tests\Feature;

use App\Models\PaymentAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentAttemptApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_can_list_and_view_payment_attempts(): void
    {
        $paymentAttempt = PaymentAttempt::factory()->create();
        Sanctum::actingAs($this->admin(), ['admin:access']);

        $this->getJson("/api/v1/admin/payment-attempts?invoice_id={$paymentAttempt->invoice_id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $paymentAttempt->id)
            ->assertJsonPath('data.0.invoice.id', $paymentAttempt->invoice_id);

        $this->getJson("/api/v1/admin/payment-attempts/{$paymentAttempt->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $paymentAttempt->id)
            ->assertJsonPath('data.invoice.invoice_number', $paymentAttempt->invoice->invoice_number);
    }

    public function test_returns_401_when_no_sanctum_token_is_provided(): void
    {
        $this->getJson('/api/v1/admin/payment-attempts')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Sesi Anda telah berakhir. Silakan masuk kembali.');
    }

    public function test_returns_403_when_authenticated_user_is_not_an_admin(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['admin:access']);

        $this->getJson('/api/v1/admin/payment-attempts')
            ->assertForbidden()
            ->assertJsonPath('message', 'Anda tidak memiliki izin untuk mengakses halaman ini.');
    }

    public function test_reconcile_action_requires_admin_authorization(): void
    {
        $paymentAttempt = PaymentAttempt::factory()->create();
        Sanctum::actingAs(User::factory()->create(), ['admin:access']);

        $this->postJson("/api/v1/admin/payment-attempts/{$paymentAttempt->id}/reconcile")
            ->assertForbidden()
            ->assertJsonPath('message', 'Anda tidak memiliki izin untuk mengakses halaman ini.');
    }

    public function test_checkout_retry_action_requires_admin_authorization(): void
    {
        $paymentAttempt = PaymentAttempt::factory()->create();
        Sanctum::actingAs(User::factory()->create(), ['admin:access']);

        $this->postJson("/api/v1/admin/payment-attempts/{$paymentAttempt->id}/checkout/retry")
            ->assertForbidden()
            ->assertJsonPath('message', 'Anda tidak memiliki izin untuk mengakses halaman ini.');
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }
}
