<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;

#[Signature('doku:verify-endpoint {--url= : URL endpoint merchants production}')]
#[Description('Memverifikasi ketersediaan HTTP endpoint DOKU production menggunakan autentikasi admin aman')]
class VerifyDokuProductionEndpointCommand extends Command
{
    public function handle(): int
    {
        $targetUrl = (string) ($this->option('url')
            ?: rtrim((string) config('app.url'), '/') . '/api/v1/admin/doku-gateway/merchants');

        $this->info("Memverifikasi HTTP endpoint DOKU: {$targetUrl}");

        $admin = User::query()->where('is_admin', true)->first();
        if ($admin === null) {
            $this->error('Verifikasi GAGAL: Tidak ditemukan user administrator pada database.');

            return self::FAILURE;
        }

        // Buat token autentikasi admin sementara (ephemeral)
        $token = $admin->createToken('ci-verify-doku-endpoint', ['admin:access'], now()->addMinutes(3));

        try {
            $response = Http::timeout(15)
                ->connectTimeout(5)
                ->withToken($token->plainTextToken)
                ->acceptJson()
                ->get($targetUrl);

            $status = $response->status();

            if ($status === 200) {
                $body = $response->json();
                if (is_array($body) && ($body['status'] ?? '') === 'success') {
                    $this->info("Verifikasi BERHASIL: HTTP 200 OK diterima dari {$targetUrl}.");

                    return self::SUCCESS;
                }

                $this->error("Verifikasi GAGAL: Respons HTTP 200 tetapi struktur payload tidak valid: {$response->body()}");

                return self::FAILURE;
            }

            if ($status === 404) {
                $this->error("Verifikasi GAGAL (HTTP 404 Not Found): Rute {$targetUrl} tidak ditemukan pada server web. Route cache mungkin usang atau server belum diperbarui.");

                return self::FAILURE;
            }

            if ($status === 401 || $status === 403) {
                $this->error("Verifikasi GAGAL (HTTP {$status}): Otorisasi admin ditolak oleh server web.");

                return self::FAILURE;
            }

            $this->error("Verifikasi GAGAL (HTTP {$status}): Respons server tidak terduga: {$response->body()}");

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error("Verifikasi GAGAL: Terjadi kesalahan koneksi ke {$targetUrl}: {$e->getMessage()}");

            return self::FAILURE;
        } finally {
            // Selalu bersihkan token sementara
            PersonalAccessToken::query()
                ->where('name', 'ci-verify-doku-endpoint')
                ->delete();
        }
    }
}
