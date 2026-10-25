<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationCustomer;
use App\Models\ApplicationIntegrationToken;
use App\Models\Partner;
use App\Models\ReferralCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GlobalIntegrationReferralApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_generates_rotates_and_revokes_a_hashed_global_token(): void
    {
        $application = Application::factory()->create();
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]), ['admin:access']);

        $first = $this->postJson("/api/v1/admin/applications/{$application->id}/global-integration-token")
            ->assertCreated()->assertJsonPath('data.token_kind', 'global')->assertJsonMissing(['token_hash'])->json('token');
        $this->assertDatabaseHas('application_integration_tokens', ['application_id' => $application->id, 'token_hash' => hash('sha256', $first)]);
        $second = $this->postJson("/api/v1/admin/applications/{$application->id}/global-integration-token/rotate")
            ->assertOk()->assertJsonMissing(['token_hash'])->json('token');
        $this->assertNotSame($first, $second);
        $this->withToken($first)->postJson('/api/v1/integrations/referrals/validate', ['referral_code' => 'REF-AAAAAA'])->assertUnprocessable();
        $this->deleteJson("/api/v1/admin/applications/{$application->id}/global-integration-token")->assertOk();
        $this->withToken($second)->postJson('/api/v1/integrations/referrals/validate', ['referral_code' => 'REF-AAAAAA'])->assertUnauthorized();
    }

    public function test_global_token_scopes_customer_and_referral_to_its_application(): void
    {
        $application = Application::factory()->create();
        $otherApplication = Application::factory()->create();
        $token = ApplicationIntegrationToken::factory()->create(['application_id' => $application->id, 'token_kind' => ApplicationIntegrationToken::KindGlobal]);
        $plaintext = 'global-'.fake()->uuid();
        $token->update(['token_hash' => hash('sha256', $plaintext)]);
        $partner = Partner::factory()->create();
        $code = ReferralCode::factory()->create(['partner_id' => $partner->id, 'code' => 'REF-A1B2C3']);

        $this->withToken($plaintext)->postJson('/api/v1/integrations/customers', ['external_customer_id' => 'SALON-1', 'name' => 'Pelanggan Baru', 'status' => 'active', 'referral_code' => $code->code])
            ->assertCreated()->assertJsonPath('referral.customer_price', '125000.00');
        $this->assertDatabaseHas('customer_referrals', ['application_id' => $application->id, 'referral_code' => $code->code]);
        ApplicationCustomer::factory()->create(['application_id' => $otherApplication->id, 'external_customer_id' => 'SALON-LAIN']);
        $this->withToken($plaintext)->getJson('/api/v1/integrations/customers/SALON-LAIN')->assertNotFound();
    }
}
