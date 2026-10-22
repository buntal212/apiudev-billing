<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\ApplicationIntegrationToken;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApplicationIntegrationToken>
 */
class ApplicationIntegrationTokenFactory extends Factory
{
    protected $model = ApplicationIntegrationToken::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'application_id' => Application::factory(),
            'name' => 'Test integration token',
            'token_hash' => hash('sha256', fake()->uuid()),
            'token_prefix' => 'udev_test',
            'scopes' => [
                ApplicationIntegrationToken::ScopeCustomersRead,
                ApplicationIntegrationToken::ScopeCustomersWrite,
            ],
            'expires_at' => null,
            'revoked_at' => null,
            'last_used_at' => null,
        ];
    }
}
