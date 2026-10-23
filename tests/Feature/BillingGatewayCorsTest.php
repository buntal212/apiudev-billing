<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BillingGatewayCorsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const string ProductionOrigin = 'https://udev.udumbara.my.id';

    private const string UnknownOrigin = 'https://tidak-dikenal.example.test';

    private const string Endpoint = '/api/v1/admin/payment-gateway/billing-transactions';

    public function test_allows_the_explicit_udev_production_origin(): void
    {
        $this->assertContains(self::ProductionOrigin, config('cors.allowed_origins'));
    }

    public function test_handles_preflight_before_sanctum_or_admin_middleware(): void
    {
        $response = $this->options(self::Endpoint, [], [
            'Origin' => self::ProductionOrigin,
            'Access-Control-Request-Method' => 'GET',
            'Access-Control-Request-Headers' => 'Authorization, Accept, Content-Type',
        ]);

        $response
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', self::ProductionOrigin)
            ->assertHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $this->assertAllowedHeaders($response);
    }

    public function test_bearer_token_request_keeps_cors_headers(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->withToken($admin->createToken('cors-test', ['admin:access'])->plainTextToken)
            ->withHeader('Origin', self::ProductionOrigin)
            ->getJson(self::Endpoint)
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', self::ProductionOrigin);
    }

    public function test_unauthenticated_and_forbidden_responses_keep_cors_headers(): void
    {
        $this->withHeader('Origin', self::ProductionOrigin)
            ->getJson(self::Endpoint)
            ->assertUnauthorized()
            ->assertHeader('Access-Control-Allow-Origin', self::ProductionOrigin);

        $user = User::factory()->create(['is_admin' => false]);
        $this->withToken($user->createToken('cors-test', ['admin:access'])->plainTextToken)
            ->withHeader('Origin', self::ProductionOrigin)
            ->getJson(self::Endpoint)
            ->assertForbidden()
            ->assertHeader('Access-Control-Allow-Origin', self::ProductionOrigin);
    }

    public function test_not_found_validation_and_server_errors_keep_cors_headers(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $token = $admin->createToken('cors-test', ['admin:access'])->plainTextToken;

        $this->withToken($token)
            ->withHeader('Origin', self::ProductionOrigin)
            ->getJson('/api/v1/admin/payment-gateway/not-found')
            ->assertNotFound()
            ->assertHeader('Access-Control-Allow-Origin', self::ProductionOrigin);

        $this->withToken($token)
            ->withHeader('Origin', self::ProductionOrigin)
            ->getJson(self::Endpoint.'?per_page=101')
            ->assertUnprocessable()
            ->assertHeader('Access-Control-Allow-Origin', self::ProductionOrigin);

        Route::get('api/testing/cors-error', function (): never {
            throw new \RuntimeException('cors test exception');
        });

        $this->withHeader('Origin', self::ProductionOrigin)
            ->getJson('/api/testing/cors-error')
            ->assertStatus(500)
            ->assertHeader('Access-Control-Allow-Origin', self::ProductionOrigin);
    }

    public function test_does_not_allow_an_unknown_origin(): void
    {
        $this->options(self::Endpoint, [], [
            'Origin' => self::UnknownOrigin,
            'Access-Control-Request-Method' => 'GET',
            'Access-Control-Request-Headers' => 'Authorization, Accept, Content-Type',
        ])
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    private function assertAllowedHeaders(TestResponse $response): void
    {
        $headers = (string) $response->headers->get('Access-Control-Allow-Headers');

        foreach (['Authorization', 'Accept', 'Content-Type'] as $header) {
            $this->assertStringContainsStringIgnoringCase($header, $headers);
        }
    }
}
