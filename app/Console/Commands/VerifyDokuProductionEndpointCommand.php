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
            $response = Http::withoutVerifying()
                ->timeout(15)
                ->connectTimeout(5)
                ->withToken($token->plainTextToken)
                ->acceptJson()
                ->get($targetUrl);

            $status = $response->status();

            if ($status !== 200) {
                if ($status === 404) {
                    $this->error("Verifikasi GAGAL (HTTP 404 Not Found): Rute {$targetUrl} tidak ditemukan pada server web. Route cache mungkin usang atau server belum diperbarui.");
                } elseif ($status === 401 || $status === 403) {
                    $this->error("Verifikasi GAGAL (HTTP {$status}): Otorisasi admin ditolak oleh server web.");
                } else {
                    $this->error("Verifikasi GAGAL (HTTP {$status}): Respons server tidak terduga: {$response->body()}");
                }

                return self::FAILURE;
            }

            $body = $response->json();
            if (! is_array($body) || ($body['status'] ?? '') !== 'success') {
                $this->error("Verifikasi GAGAL: Respons HTTP 200 tetapi struktur payload tidak valid: {$response->body()}");

                return self::FAILURE;
            }

            $this->info("Verifikasi BERHASIL: HTTP 200 OK diterima dari {$targetUrl}.");

            // Verifikasi non-destruktif ketersediaan rute DELETE configuration
            $configUrl = preg_replace('#/merchants$#', '/configuration', $targetUrl);
            if ($configUrl !== $targetUrl) {
                $probeResponse = Http::withoutVerifying()
                    ->timeout(15)
                    ->connectTimeout(5)
                    ->withToken($token->plainTextToken)
                    ->acceptJson()
                    ->delete($configUrl . '?merchant_code=__probe_route_check__');

                if ($probeResponse->status() === 405) {
                    $this->error("Verifikasi GAGAL (HTTP 405 Method Not Allowed): Rute DELETE {$configUrl} belum terdaftar pada server web.");

                    return self::FAILURE;
                }

                $this->info("Verifikasi BERHASIL: Rute DELETE {$configUrl} aktif (Status {$probeResponse->status()}: bukan 405).");
            }

            return self::SUCCESS;
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
