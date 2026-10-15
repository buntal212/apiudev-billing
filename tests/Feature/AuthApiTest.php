<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_can_log_in_with_valid_credentials(): void
    {
        $admin = User::factory()->create([
            'username' => 'admin',
            'email' => 'admin@udev.test',
            'is_admin' => true,
            'password' => Hash::make('password-yang-aman'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'username' => $admin->username,
            'password' => 'password-yang-aman',
        ])
            ->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.id', $admin->id)
            ->assertJsonPath('data.user.username', $admin->username)
            ->assertJsonPath('data.user.email', $admin->email);

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_login_rejects_an_invalid_password(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'password' => Hash::make('password-yang-aman'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'username' => $admin->username,
            'password' => 'password-yang-salah',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Username atau kata sandi yang Anda masukkan salah.')
            ->assertJsonPath('errors.username.0', 'Username atau kata sandi yang Anda masukkan salah.');
    }

    public function test_login_rejects_a_non_admin_user(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password-yang-aman'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'username' => $user->username,
            'password' => 'password-yang-aman',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Username atau kata sandi yang Anda masukkan salah.')
            ->assertJsonPath('errors.username.0', 'Username atau kata sandi yang Anda masukkan salah.');
    }

    public function test_login_requires_a_username_and_password(): void
    {
        $this->postJson('/api/v1/auth/login', [])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Data yang dikirim belum valid. Periksa kembali isian Anda.')
            ->assertJsonPath('errors.username.0', 'Username wajib diisi.')
            ->assertJsonPath('errors.password.0', 'Kata sandi wajib diisi.');
    }

    public function test_login_rejects_an_invalid_username_format(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'username' => 'bukan username',
            'password' => 'password-yang-aman',
        ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.username.0',
                'Username hanya boleh berisi huruf, angka, tanda hubung, dan garis bawah.'
            );
    }

    public function test_login_is_rate_limited_by_username_and_ip_address(): void
    {
        $payload = [
            'username' => 'admin',
            'password' => 'password-yang-salah',
        ];

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/v1/auth/login', $payload)->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', $payload)
            ->assertTooManyRequests()
            ->assertJsonPath(
                'message',
                'Terlalu banyak percobaan. Silakan tunggu beberapa saat sebelum mencoba kembali.'
            );
    }

    public function test_authenticated_admin_can_read_their_profile(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $token = $admin->createToken('test', ['admin:access']);

        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $admin->id)
            ->assertJsonPath('data.is_admin', true);
    }

    public function test_unauthenticated_requests_receive_401(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Sesi Anda telah berakhir. Silakan masuk kembali.');
    }

    public function test_non_admin_token_is_forbidden(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $token = $user->createToken('test', ['admin:access']);

        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertJsonPath('message', 'Anda tidak memiliki izin untuk mengakses halaman ini.');
    }

    public function test_unexpected_api_errors_hide_technical_details(): void
    {
        Route::get('/api/v1/test-server-error', function (): never {
            throw new RuntimeException('Database connection failed with a secret value.');
        });

        $this->getJson('/api/v1/test-server-error')
            ->assertStatus(500)
            ->assertJsonPath('message', 'Layanan sedang mengalami gangguan. Silakan coba kembali.')
            ->assertJsonMissingPath('exception')
            ->assertJsonMissingPath('trace');
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $token = $admin->createToken('test', ['admin:access']);
        $tokenId = $token->accessToken->id;

        $this->withToken($token->plainTextToken)
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
    }
}
