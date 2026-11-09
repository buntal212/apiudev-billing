# CONTOH IMPLEMENTASI RESMI LARAVEL CLIENT

## BACKEND LARISSAMA (`api.larissama`) ↔ UDEV BILLING

---

## 1. Service Class: `UdevBillingClient.php`

Letakkan file ini di backend Larissama:
`app/Services/Udev/UdevBillingClient.php`

```php
<?php

namespace App\Services\Udev;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class UdevBillingClient
{
    private string $baseUrl;
    private string $token;
    private int $timeout;
    private int $connectTimeout;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.udev.base_url', 'https://apiudev.udumbara.my.id'), '/');
        $this->token = (string) config('services.udev.token', env('UDEV_CUSTOMERS_TOKEN', ''));
        $this->timeout = (int) config('services.udev.timeout', 10);
        $this->connectTimeout = (int) config('services.udev.connect_timeout', 3);

        if (empty($this->token)) {
            Log::error('UDEV Billing Token belum dikonfigurasi pada environment Larissama.');
        }
    }

    /**
     * Membentuk instance PendingRequest dengan konfigurasi standar keamanan.
     */
    private function http(): PendingRequest
    {
        return Http::baseUrl("{$this->baseUrl}/api/v1/integrations")
            ->withToken($this->token)
            ->acceptJson()
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout);
    }

    /**
     * 1. Mengambil katalog paket langganan aktif milik Larissama.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listSubscriptionPlans(): array
    {
        try {
            $response = $this->http()->get('/subscription-plans', ['per_page' => 100]);

            if ($response->successful()) {
                return $response->json('data') ?? [];
            }

            $this->logError('listSubscriptionPlans', $response);
            throw new RuntimeException($response->json('message') ?? 'Gagal memuat katalog paket dari UDEV.');
        } catch (ConnectionException $e) {
            Log::error('Koneksi timeout saat memanggil katalog UDEV: ' . $e->getMessage());
            throw new RuntimeException('Layanan penagihan sedang tidak dapat dijangkau.');
        }
    }

    /**
     * 2. Sinkronisasi data warung pelanggan ke UDEV (Upsert).
     */
    public function syncCustomer(
        string $externalCustomerId,
        string $warungName,
        string $ownerName,
        string $email,
        string $phone
    ): array {
        try {
            $response = $this->http()->post('/customers', [
                'external_customer_id' => $externalCustomerId,
                'name' => $warungName,
                'owner_name' => $ownerName,
                'email' => $email,
                'phone' => $phone,
                'status' => 'active',
            ]);

            if ($response->successful()) {
                return $response->json('data') ?? [];
            }

            $this->logError('syncCustomer', $response, ['customer_id' => $externalCustomerId]);
            throw new RuntimeException($response->json('message') ?? 'Gagal menyinkronkan data warung ke UDEV.');
        } catch (ConnectionException $e) {
            Log::error('Timeout saat sinkronisasi customer ke UDEV: ' . $e->getMessage());
            throw new RuntimeException('Koneksi ke UDEV terputus saat sinkronisasi profil.');
        }
    }

    /**
     * 3. Simulasi preview perhitungan harga dan durasi invoice.
     */
    public function previewInvoice(string $externalCustomerId, string $planCode, int $periodCount = 1): array
    {
        try {
            $response = $this->http()->post("/customers/{$externalCustomerId}/invoice-preview", [
                'subscription_plan_code' => $planCode,
                'period_count' => max(1, min(12, $periodCount)),
            ]);

            if ($response->successful()) {
                return $response->json('data') ?? [];
            }

            $this->logError('previewInvoice', $response, ['customer_id' => $externalCustomerId, 'plan' => $planCode]);
            throw new RuntimeException($response->json('message') ?? 'Gagal menghitung simulasi harga.');
        } catch (ConnectionException $e) {
            Log::error('Timeout saat preview invoice UDEV: ' . $e->getMessage());
            throw new RuntimeException('Layanan penagihan tidak merespons.');
        }
    }

    /**
     * 4. Menerbitkan invoice penagihan pending.
     */
    public function createInvoice(
        string $externalCustomerId,
        string $planCode,
        string $dueAtIso8601,
        int $periodCount = 1,
        ?string $idempotencyKey = null
    ): array {
        $idempotencyKey = $idempotencyKey ?: ('inv-' . $externalCustomerId . '-' . time() . '-' . Str::random(6));

        try {
            $response = $this->http()
                ->withHeader('Idempotency-Key', $idempotencyKey)
                ->post("/customers/{$externalCustomerId}/invoices", [
                    'subscription_plan_code' => $planCode,
                    'due_at' => $dueAtIso8601,
                    'period_count' => max(1, min(12, $periodCount)),
                    'idempotency_key' => $idempotencyKey,
                ]);

            if ($response->status() === 201 || $response->successful()) {
                return $response->json('data') ?? [];
            }

            $this->logError('createInvoice', $response, ['customer_id' => $externalCustomerId]);
            throw new RuntimeException($response->json('message') ?? 'Gagal menerbitkan invoice di UDEV.');
        } catch (ConnectionException $e) {
            Log::error('Timeout saat membuat invoice UDEV: ' . $e->getMessage());
            throw new RuntimeException('Gagal menghubungi server UDEV untuk membuat tagihan.');
        }
    }

    /**
     * 5. Memulai sesi checkout Midtrans (QRIS / VA / GoPay).
     */
    public function checkoutMidtrans(
        string $invoiceNumber,
        string $paymentMethod = 'qris',
        ?string $bank = null,
        ?string $idempotencyKey = null
    ): array {
        $idempotencyKey = $idempotencyKey ?: ('chk-' . $invoiceNumber . '-' . Str::random(8));

        $payload = [
            'provider' => 'midtrans',
            'payment_method' => $paymentMethod,
            'idempotency_key' => $idempotencyKey,
        ];

        if (! empty($bank)) {
            $payload['bank'] = $bank;
        }

        try {
            $response = $this->http()
                ->withHeader('Idempotency-Key', $idempotencyKey)
                ->post("/invoices/{$invoiceNumber}/checkout", $payload);

            // 200 OK (Ready) atau 202 Accepted (Processing)
            if (in_array($response->status(), [200, 202], true) && $response->successful()) {
                return $response->json('data') ?? [];
            }

            $this->logError('checkoutMidtrans', $response, ['invoice' => $invoiceNumber, 'method' => $paymentMethod]);
            throw new RuntimeException($response->json('message') ?? 'Gagal memulai sesi pembayaran Midtrans.');
        } catch (ConnectionException $e) {
            Log::warning("Koneksi timeout saat checkout invoice {$invoiceNumber}. Memeriksa status...");
            // Safe fallback: jika checkout timeout, query invoice untuk cek apakah attempt sudah terbentuk
            return $this->getInvoiceDetail($invoiceNumber);
        }
    }

    /**
     * 6. Mengambil detail invoice dan status pembayaran terkini.
     */
    public function getInvoiceDetail(string $invoiceNumber, bool $reconcile = false): array
    {
        try {
            $response = $this->http()->get("/invoices/{$invoiceNumber}", [
                'reconcile' => $reconcile ? 'true' : 'false',
            ]);

            if ($response->successful()) {
                return $response->json('data') ?? [];
            }

            $this->logError('getInvoiceDetail', $response, ['invoice' => $invoiceNumber]);
            throw new RuntimeException($response->json('message') ?? 'Invoice tidak ditemukan.');
        } catch (ConnectionException $e) {
            Log::error('Timeout saat cek status invoice: ' . $e->getMessage());
            throw new RuntimeException('Tidak dapat memeriksa status invoice.');
        }
    }

    /**
     * 7. Membaca status langganan dan masa aktif warung resmi dari UDEV.
     */
    public function getCustomerSubscription(string $externalCustomerId): array
    {
        try {
            $response = $this->http()->get("/customers/{$externalCustomerId}/subscription");

            if ($response->successful()) {
                return $response->json('data') ?? [];
            }

            $this->logError('getCustomerSubscription', $response, ['customer_id' => $externalCustomerId]);
            throw new RuntimeException($response->json('message') ?? 'Data langganan tidak ditemukan.');
        } catch (ConnectionException $e) {
            Log::error('Timeout saat membaca langganan customer: ' . $e->getMessage());
            throw new RuntimeException('Tidak dapat memuat masa aktif langganan.');
        }
    }

    /**
     * Helper log error aman tanpa membocorkan token rahasia.
     */
    private function logError(string $action, Response $response, array $context = []): void
    {
        Log::warning("UDEV API Request Failed: {$action}", array_merge($context, [
            'status' => $response->status(),
            'body' => $response->json() ?? $response->body(),
        ]));
    }
}
```

---

## 2. Controller Siap Pakai: `SubscriptionPaymentController.php`

Letakkan file ini di backend Larissama:
`app/Http/Controllers/Api/V1/SubscriptionPaymentController.php`

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Warung;
use App\Services\Udev\UdevBillingClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class SubscriptionPaymentController extends Controller
{
    public function __construct(
        private readonly UdevBillingClient $udev
    ) {}

    /**
     * POST /api/v1/subscription/preview
     */
    public function preview(Request $request): JsonResponse
    {
        $warung = $this->authorizeOwnerWarung($request);
        $validated = $request->validate([
            'subscription_plan_code' => ['required', 'string'],
            'period_count' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);

        $externalId = (string) $warung->id;

        try {
            // Pastikan warung sudah tersinkronisasi di UDEV
            $this->syncWarungProfile($warung);

            $data = $this->udev->previewInvoice(
                $externalId,
                $validated['subscription_plan_code'],
                (int) ($validated['period_count'] ?? 1)
            );

            return response()->json(['data' => $data]);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /api/v1/subscription/order
     * Membuat invoice dan langsung memulai checkout Midtrans.
     */
    public function order(Request $request): JsonResponse
    {
        $warung = $this->authorizeOwnerWarung($request);
        $validated = $request->validate([
            'subscription_plan_code' => ['required', 'string'],
            'period_count' => ['nullable', 'integer', 'min:1', 'max:12'],
            'payment_method' => ['nullable', 'string'], // qris, va_bca, va_bni, va_bri, va_permata, va_mandiri, gopay
            'bank' => ['nullable', 'string'],
        ]);

        $externalId = (string) $warung->id;
        $periodCount = (int) ($validated['period_count'] ?? 1);
        $paymentMethod = $validated['payment_method'] ?? 'qris';
        $bank = $validated['bank'] ?? null;
        $idempotencyKey = $request->header('Idempotency-Key') ?: ('order-' . $warung->id . '-' . time());

        try {
            $this->syncWarungProfile($warung);

            // 1. Terbitkan Invoice (Jatuh tempo 24 jam)
            $dueAt = CarbonImmutable::now()->addHours(24)->toISOString();
            $invoice = $this->udev->createInvoice(
                $externalId,
                $validated['subscription_plan_code'],
                $dueAt,
                $periodCount,
                $idempotencyKey . '-inv'
            );

            $invoiceNumber = $invoice['invoice_number'];

            // 2. Lakukan Checkout Midtrans
            $checkoutData = $this->udev->checkoutMidtrans(
                $invoiceNumber,
                $paymentMethod,
                $bank,
                $idempotencyKey . '-chk'
            );

            return response()->json(['data' => $checkoutData], 201);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /api/v1/subscription/status/{invoiceNumber}
     * Cek status pembayaran dan sinkronkan masa aktif warung jika sudah lunas.
     */
    public function status(Request $request, string $invoiceNumber): JsonResponse
    {
        $warung = $this->authorizeOwnerWarung($request);

        try {
            $invoiceData = $this->udev->getInvoiceDetail($invoiceNumber);

            // Jika status invoice sudah PAID, sinkronkan tanggal berakhir warung
            if (($invoiceData['status'] ?? '') === 'paid') {
                $subscription = $invoiceData['subscription'] ?? null;
                if ($subscription && ! empty($subscription['expires_at'])) {
                    $newExpiry = CarbonImmutable::parse($subscription['expires_at']);

                    if ($warung->tanggal_berakhir === null || $newExpiry->toDateString() !== $warung->tanggal_berakhir->toDateString()) {
                        $warung->tanggal_berakhir = $newExpiry->toDateString();
                        $warung->aktif = true;
                        $warung->save();
                    }
                }
            }

            return response()->json(['data' => $invoiceData]);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }

    /**
     * GET /api/v1/subscription/current
     * Mengambil profil status langganan terkini warung dari UDEV.
     */
    public function current(Request $request): JsonResponse
    {
        $warung = $this->authorizeOwnerWarung($request);

        try {
            $data = $this->udev->getCustomerSubscription((string) $warung->id);

            return response()->json([
                'data' => [
                    'warung_id' => $warung->id,
                    'nama' => $warung->nama,
                    'status_langganan' => $warung->aktif ? 'aktif' : 'kedaluwarsa',
                    'tanggal_mulai' => $warung->tanggal_mulai?->toDateString(),
                    'tanggal_berakhir' => $warung->tanggal_berakhir?->toDateString(),
                    'udev_subscription' => $data['subscription'] ?? null,
                    'referral' => $data['referral'] ?? null,
                ],
            ]);
        } catch (Throwable) {
            // Fallback ke data database lokal jika UDEV sedang offline
            return response()->json([
                'data' => [
                    'warung_id' => $warung->id,
                    'nama' => $warung->nama,
                    'status_langganan' => $warung->aktif ? 'aktif' : 'kedaluwarsa',
                    'tanggal_mulai' => $warung->tanggal_mulai?->toDateString(),
                    'tanggal_berakhir' => $warung->tanggal_berakhir?->toDateString(),
                    'udev_subscription' => null,
                ],
            ]);
        }
    }

    /**
     * POST /api/v1/subscription/invoices/{invoiceNumber}/retry
     */
    public function retry(Request $request, string $invoiceNumber): JsonResponse
    {
        $this->authorizeOwnerWarung($request);
        $validated = $request->validate([
            'payment_method' => ['nullable', 'string'],
            'bank' => ['nullable', 'string'],
        ]);

        $idempotencyKey = $request->header('Idempotency-Key') ?: ('retry-' . time() . '-' . Str::random(6));

        try {
            $checkoutData = $this->udev->checkoutMidtrans(
                $invoiceNumber,
                $validated['payment_method'] ?? 'qris',
                $validated['bank'] ?? null,
                $idempotencyKey
            );

            return response()->json(['data' => $checkoutData]);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Helper otorisasi: hanya owner yang memiliki warung yang diizinkan.
     */
    private function authorizeOwnerWarung(Request $request): Warung
    {
        $user = $request->user();
        if (! $user instanceof User || $user->role !== 'owner' || $user->warung_id === null) {
            abort(403, 'Hanya pemilik warung yang dapat mengelola pembayaran langganan.');
        }

        $warung = Warung::find($user->warung_id);
        if ($warung === null) {
            abort(404, 'Data warung tidak ditemukan.');
        }

        return $warung;
    }

    private function syncWarungProfile(Warung $warung): void
    {
        try {
            $owner = $warung->users()->where('role', 'owner')->first();
            $this->udev->syncCustomer(
                (string) $warung->id,
                $warung->nama,
                $owner?->nama ?? $warung->nama,
                $owner?->email ?? "warung{$warung->id}@larissama.test",
                $warung->telepon ?? '081234567890'
            );
        } catch (Throwable $e) {
            Log::info("Sinkronisasi profil warung {$warung->id} diabaikan: " . $e->getMessage());
        }
    }
}
```
