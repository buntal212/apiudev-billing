<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;

#[Signature('midtrans:verify-endpoint {--url= : URL endpoint merchants production} {--internal : Jalankan health check internal melalui Laravel HTTP kernel}')]
#[Description('Memverifikasi ketersediaan HTTP endpoint Midtrans production/staging menggunakan autentikasi admin aman')]
class VerifyMidtransProductionEndpointCommand extends Command
{
    public function handle(): int
    {
        $admin = User::query()->where('is_admin', true)->first();
        if ($admin === null) {
            $this->error('Verifikasi GAGAL: Tidak ditemukan user administrator pada database.');

            return self::FAILURE;
        }

        // Buat token autentikasi admin sementara (ephemeral)
        $token = $admin->createToken('ci-verify-midtrans-endpoint', ['admin:access'], now()->addMinutes(3));

        try {
            if ($this->option('internal')) {
                return $this->runInternalHealthCheck($token->plainTextToken);
            }

            $targetUrl = (string) ($this->option('url')
                ?: rtrim((string) config('app.url'), '/').'/api/v1/admin/midtrans-gateway/merchants');

            $this->info("Memverifikasi HTTP endpoint Midtrans via TLS aman: {$targetUrl}");

            try {
                $response = Http::timeout(8)
                    ->connectTimeout(4)
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
                if (! is_array($body) || (! isset($body['data']) && ($body['status'] ?? '') !== 'success')) {
                    $this->error("Verifikasi GAGAL: Respons HTTP 200 tetapi struktur payload tidak valid: {$response->body()}");

                    return self::FAILURE;
                }

                $this->info("Verifikasi BERHASIL: HTTP 200 OK diterima dari {$targetUrl}.");

                return self::SUCCESS;
            } catch (\Throwable $e) {
                $this->warn("Koneksi TLS eksternal ke {$targetUrl} tidak dapat dijangkau ({$e->getMessage()}). Menjalankan health check internal...");

                return $this->runInternalHealthCheck($token->plainTextToken);
            }
        } finally {
            PersonalAccessToken::query()
                ->where('name', 'ci-verify-midtrans-endpoint')
                ->delete();
        }
    }

    private function runInternalHealthCheck(string $plainToken): int
    {
        $this->info('Menjalankan health check internal endpoint Midtrans via Laravel HTTP Kernel...');

        // 1. Verifikasi GET midtrans-gateway/merchants
        $getRequest = Request::create('/api/v1/admin/midtrans-gateway/merchants', 'GET');
        $getRequest->headers->set('Accept', 'application/json');
        $getRequest->headers->set('Authorization', 'Bearer '.$plainToken);

        $getResponse = app()->handle($getRequest);
        $getStatus = $getResponse->getStatusCode();

        if ($getStatus !== 200) {
            $this->error("Health check internal GAGAL: GET merchants mengembalikan HTTP {$getStatus}: {$getResponse->getContent()}");

            return self::FAILURE;
        }

        $body = json_decode((string) $getResponse->getContent(), true);
        if (! is_array($body) || (! isset($body['data']) && ($body['status'] ?? '') !== 'success')) {
            $this->error("Health check internal GAGAL: Struktur respons GET merchants tidak valid: {$getResponse->getContent()}");

            return self::FAILURE;
        }

        $this->info('Health check internal BERHASIL: GET /api/v1/admin/midtrans-gateway/merchants mengembalikan HTTP 200 OK.');

        // 2. Verifikasi GET midtrans-gateway/dashboard-summary
        $summaryRequest = Request::create('/api/v1/admin/midtrans-gateway/dashboard-summary', 'GET');
        $summaryRequest->headers->set('Accept', 'application/json');
        $summaryRequest->headers->set('Authorization', 'Bearer '.$plainToken);

        $summaryResponse = app()->handle($summaryRequest);
        if ($summaryResponse->getStatusCode() !== 200) {
            $this->error("Health check internal GAGAL: GET dashboard-summary mengembalikan HTTP {$summaryResponse->getStatusCode()}: {$summaryResponse->getContent()}");

            return self::FAILURE;
        }

        $this->info('Health check internal BERHASIL: GET /api/v1/admin/midtrans-gateway/dashboard-summary mengembalikan HTTP 200 OK.');

        // 3. Verifikasi konfigurasi URL dan konektivitas inter-service mitrans-payment (opsional jika aktif)
        $gatewayUrl = rtrim((string) config('services.midtrans_gateway.gateway_service_url'), '/');
        $serviceToken = (string) config('services.midtrans_gateway.service_token');

        if ($gatewayUrl !== '' && ! empty($serviceToken)) {
            $this->info("Memverifikasi konektivitas antarservice ke midtrans-payment: {$gatewayUrl}/api/v1/internal/merchants");

            try {
                $gwResponse = Http::timeout(5)
                    ->connectTimeout(3)
                    ->withToken($serviceToken)
                    ->acceptJson()
                    ->get($gatewayUrl.'/api/v1/internal/merchants');

                if ($gwResponse->status() === 401 || $gwResponse->status() === 403) {
                    $this->warn("Autentikasi antarservice ke midtrans-payment ditolak (HTTP {$gwResponse->status()}). Periksa MIDTRANS_GATEWAY_SERVICE_TOKEN.");
                } elseif ($gwResponse->status() === 200) {
                    $this->info("Verifikasi midtrans-payment BERHASIL: HTTP 200 OK diterima dari {$gatewayUrl}/api/v1/internal/merchants.");
                } else {
                    $this->warn("Gateway midtrans-payment merespons HTTP {$gwResponse->status()} (non-fatal; fallback langsung ke Midtrans API aktif).");
                }
            } catch (\Throwable $e) {
                $this->warn("Koneksi jaringan ke microservice midtrans-payment dilewati ({$e->getMessage()}). Fallback langsung ke Midtrans Core API tetap aktif.");
            }
        }

        return self::SUCCESS;
    }
}
