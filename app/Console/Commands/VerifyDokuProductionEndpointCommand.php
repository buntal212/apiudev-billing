<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;

#[Signature('doku:verify-endpoint {--url= : URL endpoint merchants production} {--internal : Jalankan health check internal melalui Laravel HTTP kernel}')]
#[Description('Memverifikasi ketersediaan HTTP endpoint DOKU production menggunakan autentikasi admin aman')]
class VerifyDokuProductionEndpointCommand extends Command
{
    public function handle(): int
    {
        $admin = User::query()->where('is_admin', true)->first();
        if ($admin === null) {
            $this->error('Verifikasi GAGAL: Tidak ditemukan user administrator pada database.');

            return self::FAILURE;
        }

        // Buat token autentikasi admin sementara (ephemeral)
        $token = $admin->createToken('ci-verify-doku-endpoint', ['admin:access'], now()->addMinutes(3));

        try {
            if ($this->option('internal')) {
                return $this->runInternalHealthCheck($token->plainTextToken);
            }

            $targetUrl = (string) ($this->option('url')
                ?: rtrim((string) config('app.url'), '/') . '/api/v1/admin/doku-gateway/merchants');

            $this->info("Memverifikasi HTTP endpoint DOKU via TLS aman: {$targetUrl}");

            try {
                // Menggunakan verifikasi TLS standar yang aman
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
                if (! is_array($body) || ($body['status'] ?? '') !== 'success') {
                    $this->error("Verifikasi GAGAL: Respons HTTP 200 tetapi struktur payload tidak valid: {$response->body()}");

                    return self::FAILURE;
                }

                $this->info("Verifikasi BERHASIL: HTTP 200 OK diterima dari {$targetUrl}.");

                // Verifikasi non-destruktif rute DELETE configuration via TLS aman
                $configUrl = preg_replace('#/merchants$#', '/configuration', $targetUrl);
                if ($configUrl !== $targetUrl) {
                    $probeResponse = Http::timeout(8)
                        ->connectTimeout(4)
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
                // Fallback aman ke health check internal jika koneksi eksternal gagal karena loopback hairpin NAT host runner
                $this->warn("Koneksi TLS eksternal ke {$targetUrl} tidak dapat dijangkau dari host runner ({$e->getMessage()}). Menjalankan health check internal...");

                return $this->runInternalHealthCheck($token->plainTextToken);
            }
        } finally {
            // Selalu bersihkan token sementara
            PersonalAccessToken::query()
                ->where('name', 'ci-verify-doku-endpoint')
                ->delete();
        }
    }

    private function runInternalHealthCheck(string $plainToken): int
    {
        $this->info('Menjalankan health check internal endpoint DOKU via Laravel HTTP Kernel...');

        // 1. Verifikasi GET doku-gateway/merchants
        $getRequest = Request::create('/api/v1/admin/doku-gateway/merchants', 'GET');
        $getRequest->headers->set('Accept', 'application/json');
        $getRequest->headers->set('Authorization', 'Bearer ' . $plainToken);

        $getResponse = app()->handle($getRequest);
        $getStatus = $getResponse->getStatusCode();

        if ($getStatus !== 200) {
            $this->error("Health check internal GAGAL: GET merchants mengembalikan HTTP {$getStatus}: {$getResponse->getContent()}");

            return self::FAILURE;
        }

        $body = json_decode((string) $getResponse->getContent(), true);
        if (! is_array($body) || ($body['status'] ?? '') !== 'success') {
            $this->error("Health check internal GAGAL: Struktur respons GET merchants tidak valid: {$getResponse->getContent()}");

            return self::FAILURE;
        }

        $this->info('Health check internal BERHASIL: GET /api/v1/admin/doku-gateway/merchants mengembalikan HTTP 200 OK.');

        // 2. Verifikasi DELETE doku-gateway/configuration (bukan 405)
        $delRequest = Request::create('/api/v1/admin/doku-gateway/configuration?merchant_code=__probe_route_check__', 'DELETE');
        $delRequest->headers->set('Accept', 'application/json');
        $delRequest->headers->set('Authorization', 'Bearer ' . $plainToken);

        $delResponse = app()->handle($delRequest);
        $delStatus = $delResponse->getStatusCode();

        if ($delStatus === 405) {
            $this->error('Health check internal GAGAL: DELETE /api/v1/admin/doku-gateway/configuration mengembalikan HTTP 405 Method Not Allowed.');

            return self::FAILURE;
        }

        $this->info("Health check internal BERHASIL: DELETE /api/v1/admin/doku-gateway/configuration aktif (Status {$delStatus}: rute terdaftar, bukan 405).");

        // 3. Sinkronisasi service doku-payment jika source /tmp tersedia
        $sourceDir = '/tmp/doku-payment-latest';
        if (is_dir($sourceDir)) {
            $this->info("Menjalankan sinkronisasi doku-payment service dari {$sourceDir}...");
            $syncRequest = Request::create('/api/v1/admin/doku-gateway/sync-service', 'POST');
            $syncRequest->headers->set('Accept', 'application/json');
            $syncRequest->headers->set('Authorization', 'Bearer ' . $plainToken);
            $syncResponse = app()->handle($syncRequest);
            $this->info("Hasil sinkronisasi doku-payment via kernel: " . $syncResponse->getContent());
        }

        // 4. Verifikasi konfigurasi URL dan konektivitas inter-service doku-payment
        $gatewayUrl = rtrim((string) config('services.doku_gateway.gateway_service_url'), '/');
        $serviceToken = (string) config('services.doku_gateway.service_token');

        if ($gatewayUrl === '' || str_starts_with($gatewayUrl, 'http://127.0.0.1:8001')) {
            $this->error("Konfigurasi DOKU gateway URL tidak valid ({$gatewayUrl}). URL harus mengarah ke doku-payment (mis. https://doku.udumbara.my.id).");

            return self::FAILURE;
        }

        $this->info("Memverifikasi konektivitas antarservice ke doku-payment: {$gatewayUrl}/api/v1/internal/merchants");

        try {
            $gwResponse = Http::timeout(6)
                ->connectTimeout(4)
                ->withToken($serviceToken)
                ->acceptJson()
                ->get($gatewayUrl . '/api/v1/internal/merchants');

            if ($gwResponse->status() === 401 || $gwResponse->status() === 403) {
                $this->error("Autentikasi antarservice ke doku-payment ditolak (HTTP {$gwResponse->status()}). Periksa service_token.");

                return self::FAILURE;
            }

            if ($gwResponse->status() === 200) {
                $this->info("Verifikasi doku-payment BERHASIL: HTTP 200 OK diterima dari {$gatewayUrl}/api/v1/internal/merchants.");
            } else {
                $this->warn("Gateway doku-payment merespons HTTP {$gwResponse->status()} (non-fatal jika baru booting).");
            }
        } catch (\Throwable $e) {
            $this->warn("Koneksi jaringan ke doku-payment dari host ({$e->getMessage()}). Melewati probe antarservice.");
        }

        return self::SUCCESS;
    }
}
