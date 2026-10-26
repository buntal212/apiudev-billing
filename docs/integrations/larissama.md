# Integrasi Larissama dengan UDEV Billing (DANA Sandbox)

Dokumen ini adalah spesifikasi dan panduan integrasi resmi untuk aplikasi **Larissama** yang terhubung ke **UDEV Billing Engine** dengan payment gateway **DANA Sandbox**.

Semua endpoint integrasi menggunakan base URL API UDEV yang dikonfigurasi pada environment Larissama:

```text
{UDEV_API_BASE}/api/v1/integrations
```

---

## 1. Prinsip Utama & Arsitektur Keamanan

1. **Isolasi Data Aplikasi**: Semua data pelanggan, paket, invoice, transaksi, dan subscription terisolasi secara ketat berdasarkan aplikasi yang terikat pada Global API Token.
2. **Kedaulatan Nominal & Durasi**: Larissama **tidak boleh** menentukan nominal Rupiah, currency, durasi hari, atau environment gateway secara sepihak. Seluruh kalkulasi harga dan durasi berasal dari UDEV Billing.
3. **Konfirmasi Gateway yang Sah (Zero Fake Settlement)**:
   - Invoice **hanya berubah menjadi `paid`** setelah konfirmasi pembayaran gateway DANA terverifikasi secara kriptografis dan nominal/referensinya cocok.
   - Endpoint simulasi atau bypass internal **tidak dapat** menandai invoice `paid` atau mengaktifkan subscription.
4. **Idempotensi Penuh**: Setiap request mutasi data (`POST`, `PATCH`) wajib menyertakan header `Idempotency-Key` untuk mencegah invoice ganda, checkout ganda, dan aktivasi ganda akibat network retry.

---

## 2. Autentikasi & Header Permintaan

Setiap HTTP request dari backend Larissama ke UDEV Billing wajib menyertakan header:

```http
Authorization: Bearer <global-integration-token>
Accept: application/json
Content-Type: application/json
```

Untuk setiap endpoint mutasi data (`POST`, `PATCH`), sertakan header idempotensi:

```http
Idempotency-Key: laris-inv-SALON-001-20261026-001
```

> [!IMPORTANT]
> **Keamanan Kredensial**: Simpan Global API Token hanya di server backend Larissama (misal `.env` / secret manager). Jangan pernah mengekspos token ke aplikasi klien (browser, mobile app) atau mencatatnya di application log.

---

## 3. Katalog Paket & Ketentuan Periode (1–12 Bulan)

### Aturan Periode & Kalkulasi Nominal:
- Pelanggan memilih **1 paket** langganan dan menentukan **jumlah periode (1 sampai 12)**.
- **1 periode = 30 hari kalender tetap** (bukan bulan masehi yang bervariasi).
- Total durasi aktif = $30 \times \text{period\_count}$ hari (misal 3 periode = 90 hari, 12 periode = 360 hari).
- Total nominal = $(\text{Harga Dasar Paket} \times \text{period\_count}) - \text{Diskon Referral (jika ada)}$.

### `GET /subscription-plans`
Mengambil daftar paket langganan aktif yang tersedia untuk aplikasi Larissama.

#### Query Parameters:
| Parameter | Tipe | Default | Keterangan |
|---|---|---|---|
| `per_page` | integer | `15` | Jumlah data per halaman (1–100) |
| `page` | integer | `1` | Nomor halaman |

#### Contoh Respons:
```json
{
  "data": [
    {
      "id": 10,
      "code": "BASIC-SALON",
      "name": "Paket Basic Salon",
      "description": "Akses manajemen kasir dan reservasi salon.",
      "price": "100000.00",
      "currency": "IDR",
      "duration_days": 30,
      "is_active": true
    },
    {
      "id": 11,
      "code": "PRO-SALON",
      "name": "Paket Pro Salon",
      "description": "Fitur lengkap multi-cabang, inventori, dan komisi staf.",
      "price": "250000.00",
      "currency": "IDR",
      "duration_days": 30,
      "is_active": true
    }
  ],
  "links": {
    "first": "https://api.udev.test/api/v1/integrations/subscription-plans?page=1",
    "last": "https://api.udev.test/api/v1/integrations/subscription-plans?page=1",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "per_page": 15,
    "to": 2,
    "total": 2
  }
}
```

---

## 4. Manajemen Pelanggan

Pelanggan diidentifikasi oleh `external_customer_id` yang unik dan stabil dari database Larissama (misal: ID toko / tenant / salon).

### `POST /customers`
Mendaftarkan atau menyinkronkan pelanggan Larissama ke UDEV.

#### Request Body:
```json
{
  "external_customer_id": "SALON-001",
  "name": "Salon Cantika BSD",
  "owner_name": "Siti Rahma",
  "email": "cantika.bsd@example.test",
  "phone": "081234567890",
  "status": "active"
}
```

#### Contoh Respons (`201 Created` atau `200 OK`):
```json
{
  "data": {
    "external_customer_id": "SALON-001",
    "name": "Salon Cantika BSD",
    "owner_name": "Siti Rahma",
    "email": "cantika.bsd@example.test",
    "phone_number": "081234567890",
    "is_active": true,
    "source": "integration",
    "sync_status": "synced"
  },
  "sync": {
    "status": "accepted"
  },
  "request_id": "req-01HZY0Z1P8..."
}
```

---

## 5. Preview & Pembuatan Invoice (1–12 Periode)

### `POST /customers/{externalId}/invoice-preview`
Digunakan oleh antarmuka kasir/pemilik Larissama untuk melihat pratinjau kalkulasi nominal harga dan durasi hari sebelum invoice dibuat secara resmi. Endpoint ini **tidak** membuat rekaman invoice di database.

#### Request Body:
```json
{
  "subscription_plan_code": "BASIC-SALON",
  "period_count": 3
}
```

#### Contoh Respons (`200 OK`):
```json
{
  "data": {
    "customer_external_id": "SALON-001",
    "period_count": 3,
    "plan": {
      "code": "BASIC-SALON",
      "name": "Paket Basic Salon",
      "duration_days": 90,
      "period_count": 3
    },
    "pricing": {
      "unit_price": "100000.00",
      "period_count": 3,
      "normal_price": "300000.00",
      "discount": "0.00",
      "final_price": "300000.00",
      "currency": "IDR"
    }
  },
  "request_id": "req-01HZY11B4..."
}
```

---

### `POST /customers/{externalId}/invoices`
Membuat invoice pembayaran baru. Satu transaksi pembelian menghasilkan tepat satu invoice.

#### Header:
```http
Idempotency-Key: laris-inv-SALON-001-20261026-001
```

#### Request Body:
```json
{
  "subscription_plan_code": "BASIC-SALON",
  "period_count": 3,
  "due_at": "2026-10-27T12:00:00+07:00"
}
```

#### Aturan Validasi:
- `subscription_plan_code`: Wajib berupa kode paket aktif milik aplikasi Larissama.
- `period_count`: Opsional, integer antara `1` sampai `12` (default `1`).
- `due_at`: Wajib berupa timestamp ISO 8601 di masa depan.
- **Pencegahan Invoice Ganda**: Jika pelanggan sudah memiliki invoice aktif yang belum dibayar (`pending`) untuk paket yang sama, atau jika request dikirim berulang dengan `Idempotency-Key` yang sama, UDEV mengembalikan invoice yang sama secara idempoten tanpa membuat invoice ganda.

#### Contoh Respons (`201 Created` atau `200 OK`):
```json
{
  "data": {
    "invoice_number": "INV-20261026-SALON01",
    "status": "pending",
    "issued_at": "2026-10-26T14:30:00.000000Z",
    "due_at": "2026-10-27T05:00:00.000000Z",
    "paid_at": null,
    "period_count": 3,
    "duration_days": 90,
    "plan": {
      "code": "BASIC-SALON",
      "name": "Paket Basic Salon",
      "duration_days": 90,
      "period_count": 3
    },
    "pricing": {
      "normal_price": "300000.00",
      "discount": "0.00",
      "final_price": "300000.00",
      "currency": "IDR"
    },
    "payment": null,
    "subscription": null,
    "tracking": {
      "settlement_id": null,
      "settlement_status": null
    }
  },
  "request_id": "req-01HZY14A7..."
}
```

---

## 6. Checkout DANA Sandbox (QRIS & DANA Wallet)

### `POST /invoices/{invoiceNumber}/checkout`
Menginisialisasi sesi pembayaran dengan gateway DANA Sandbox. Mendukung metode pembayaran QRIS (`payment_method: "qris"`) dan DANA Wallet Web Redirect (`payment_method: "dana_wallet"`).

#### Header:
```http
Idempotency-Key: laris-chk-INV-20261026-SALON01-01
```

#### Request Body:
```json
{
  "payment_method": "qris"
}
```
*Catatan: `payment_method` bersifat opsional; nilai yang didukung adalah `"qris"` (default & direkomendasikan) atau `"dana_wallet"`.*

#### Metode Pembayaran yang Didukung:
1. **`qris` (Default & Rekomendasi Utama)**:
   - Menghasilkan payload string QRIS standar EMVCo (`qr_content` / `payment_code`) dan objek instruksi pembayaran (`payment_instruction`).
   - Larissama merender kode QR secara lokal di browser/aplikasi pelanggan (misal menggunakan library `qrcode`).
   - Pelanggan memindai QR menggunakan aplikasi DANA atau e-wallet/mobile banking mana pun yang mendukung QRIS Sandbox.
   - Sesi QRIS memiliki batas kedaluwarsa (`checkout_expires_at`).
2. **`dana_wallet`**:
   - Menghasilkan `checkout_url` untuk pengalihan (redirect) browser langsung ke antarmuka web DANA Sandbox.

#### Mekanisme Kerja & State Lifecycle:
1. UDEV Billing mencocokkan konfigurasi merchant DANA Sandbox aktif yang dialokasikan untuk Larissama.
2. Memeriksa sesi pembayaran aktif sebelumnya:
   - Jika sesi sebelumnya sudah kedaluwarsa (`checkout_expires_at <= now()`), UDEV otomatis menandai attempt lama sebagai `expired` dan membuat sesi baru.
   - Jika pelanggan mengganti metode pembayaran (misal dari DANA Wallet ke QRIS), attempt lama otomatis dibatalkan (`cancelled`) untuk memberi jalan pada attempt baru.
3. Membentuk entitas `PaymentAttempt` dan membuat outbox message secara transactional.
4. Menghubungi DANA Sandbox Gateway:
   - Untuk QRIS: Meminta payload QRIS (`NETWORK_PAY` + `NETWORK_PAY_PG_QRIS`) dan menerima data string QRIS di `additionalInfo.paymentCode`.
   - Untuk DANA Wallet: Meminta sesi checkout dan menerima `checkout_url`.
5. **Status invoice tetap `pending`**. Status invoice **tidak pernah** berubah menjadi `paid` hanya karena sesi checkout berhasil dibuat atau kode QR ditampilkan. Pembayaran hanya sah setelah webhook konfirmasi pembayaran gateway terverifikasi.

#### Contoh Respons QRIS Ready (`200 OK` atau `202 Accepted`):
```json
{
  "data": {
    "invoice_number": "INV-20261026-SALON01",
    "status": "pending",
    "period_count": 3,
    "duration_days": 90,
    "payment": {
      "status": "pending",
      "provider": "dana",
      "payment_method": "qris",
      "checkout_status": "ready",
      "checkout_url": null,
      "qr_content": "00020101021226680014ID.DANA.WWW01189360091100220138240215INV20261026SALON015204581253033605802ID5914UDEV BILLING6007JAKARTA61051234062320115INV20261026SALON010708DANA00016304ABCD",
      "payment_code": "00020101021226680014ID.DANA.WWW01189360091100220138240215INV20261026SALON015204581253033605802ID5914UDEV BILLING6007JAKARTA61051234062320115INV20261026SALON010708DANA00016304ABCD",
      "payment_instruction": {
        "instruction_type": "payment_code",
        "payment_code": "00020101021226680014ID.DANA.WWW01189360091100220138240215INV20261026SALON015204581253033605802ID5914UDEV BILLING6007JAKARTA61051234062320115INV20261026SALON010708DANA00016304ABCD",
        "expires_at": "2026-10-26T15:00:00.000000Z"
      },
      "checkout_expires_at": "2026-10-26T15:00:00.000000Z",
      "transaction_id": "INV-20261026-SALON01-A1",
      "completed_at": null,
      "failure_code": null
    },
    "subscription": null
  },
  "request_id": "req-01HZY17G3..."
}
```

#### Contoh Respons DANA Wallet (`200 OK` atau `202 Accepted`):
```json
{
  "data": {
    "invoice_number": "INV-20261026-SALON01",
    "status": "pending",
    "period_count": 3,
    "duration_days": 90,
    "payment": {
      "status": "pending",
      "provider": "dana",
      "payment_method": "dana_wallet",
      "checkout_status": "ready",
      "checkout_url": "https://sandbox.dana.id/checkout/order-session-uuid-001",
      "qr_content": null,
      "payment_code": null,
      "payment_instruction": null,
      "checkout_expires_at": "2026-10-26T15:00:00.000000Z",
      "transaction_id": "INV-20261026-SALON01-A2",
      "completed_at": null,
      "failure_code": null
    },
    "subscription": null
  },
  "request_id": "req-01HZY17G3..."
}
```

#### Siklus Status Percobaan Pembayaran (Payment Attempt Lifecycle):
- **`pending`**: Sesi QRIS atau DANA Wallet sedang aktif dan menunggu pembayaran pelanggan.
- **`succeeded`**: Gateway DANA mengonfirmasi dana telah berhasil ditarik/diterima. Invoice berstatus `paid` dan langganan aktif.
- **`failed`**: Pembayaran ditolak oleh gateway atau dibatalkan.
- **`expired`**: Waktu `checkout_expires_at` telah terlewati sebelum pembayaran selesai. Klien dapat memicu retry checkout.
- **`cancelled`**: Attempt dibatalkan karena ada inisiasi pembayaran baru dengan metode berbeda.

#### Penanganan QR Kedaluwarsa & Retry Checkout:
Jika kode QR kedaluwarsa (waktu habis pada countdown), antarmuka klien harus menampilkan tombol **"Coba lagi / Buat QR Baru"**. Pemanggilan ulang endpoint checkout dengan `payment_method: "qris"` akan membatalkan sesi lama yang expired dan menghasilkan kode QRIS baru dengan masa aktif yang baru.

---

## 7. Status Pembayaran & Langganan (Polling & Verifikasi)

Larissama memeriksa status invoice dengan memanggil endpoint inquiry status secara berkala (polling dengan interval progresif 3–5 detik).

### `GET /invoices/{invoiceNumber}`
Mengambil status terkini dari invoice, status percobaan pembayaran, dan subscription yang aktif.

#### Contoh Respons Saat Pembayaran Terverifikasi (`status: paid`):
```json
{
  "data": {
    "invoice_number": "INV-20261026-SALON01",
    "status": "paid",
    "issued_at": "2026-10-26T14:30:00.000000Z",
    "due_at": "2026-10-27T05:00:00.000000Z",
    "paid_at": "2026-10-26T14:35:12.000000Z",
    "period_count": 3,
    "duration_days": 90,
    "plan": {
      "code": "BASIC-SALON",
      "name": "Paket Basic Salon",
      "duration_days": 90,
      "period_count": 3
    },
    "pricing": {
      "normal_price": "300000.00",
      "discount": "0.00",
      "final_price": "300000.00",
      "currency": "IDR"
    },
    "payment": {
      "status": "succeeded",
      "provider": "dana",
      "payment_method": "qris",
      "checkout_status": "ready",
      "checkout_url": null,
      "qr_content": "00020101021226680014ID.DANA.WWW...",
      "payment_code": "00020101021226680014ID.DANA.WWW...",
      "payment_instruction": {
        "instruction_type": "payment_code",
        "payment_code": "00020101021226680014ID.DANA.WWW...",
        "expires_at": "2026-10-26T15:00:00.000000Z"
      },
      "checkout_expires_at": "2026-10-26T15:00:00.000000Z",
      "transaction_id": "INV-20261026-SALON01-A1",
      "completed_at": "2026-10-26T14:35:12.000000Z",
      "failure_code": null
    },
    "subscription": {
      "status": "active",
      "plan_code": "BASIC-SALON",
      "starts_at": "2026-10-26T14:35:12.000000Z",
      "expires_at": "2027-01-24T14:35:12.000000Z"
    },
    "tracking": {
      "settlement_id": "01HZY18F...",
      "settlement_status": "completed"
    }
  },
  "request_id": "req-01HZY18K9..."
}
```

---

### `GET /customers/{externalId}/subscription`
Mengecek status dan masa aktif langganan pelanggan saat ini.

#### Contoh Respons:
```json
{
  "data": {
    "customer_external_id": "SALON-001",
    "subscription": {
      "status": "active",
      "plan_code": "BASIC-SALON",
      "starts_at": "2026-10-26T14:35:12.000000Z",
      "expires_at": "2027-01-24T14:35:12.000000Z"
    }
  },
  "request_id": "req-01HZY19X2..."
}
```

---

## 8. Aturan Masa Aktif Langganan & Pergantian Paket

UDEV Billing menerapkan aturan masa aktif subscription sebagai berikut:

| Kondisi Transaksi | Dampak terhadap Subscription |
|---|---|
| **Aktivasi Awal** (belum pernah langganan atau sudah expired) | `starts_at = paid_at`, `expires_at = paid_at + (30 * period_count) hari`. |
| **Perpanjangan Paket yang Sama** (langganan masih aktif dengan sisa $X$ hari) | `expires_at` bertambah $30 \times \text{period\_count}$ hari **dari tanggal kadaluwarsa sebelumnya**. Seluruh sisa hari aktif tersimpan utuh. |
| **Pergantian Paket** (misal dari `BASIC-SALON` ke `PRO-SALON` dengan sisa $15$ hari aktif) | `plan_id` langsung berganti ke paket baru (`PRO-SALON`), dan tanggal kedaluwarsa diperpanjang dari masa aktif sebelumnya: $\text{Baru} = \text{Lama} + (30 \times \text{period\_count})$. **Sisa hari aktif tidak hilang**. |
| **Pembayaran Gagal / Dibatalkan di Gateway** | Percobaan pembayaran dicatat sebagai `failed` / `cancelled`. **Status dan tanggal kedaluwarsa subscription tidak berubah sama sekali**. |
| **Webhook / Rekonsiliasi Berulang** | Dilindungi kunci idempotensi unik pada tabel `payment_allocations`. Durasi hanya ditambahkan **satu kali** (pencegahan aktivasi ganda). |

---

## 9. Kode Respons & Penanganan Error

| Status HTTP | Penyebab | Solusi & Penanganan |
|---|---|---|
| `401 Unauthorized` | Token tidak disertakan, format bearer salah, atau token sudah dicabut/kedaluwarsa. | Periksa kredensial token di backend Larissama. |
| `403 Forbidden` | Token bukan bertipe global atau aplikasi Larissama dinonaktifkan. | Hubungi Administrator UDEV Billing. |
| `404 Not Found` | Pelanggan (`externalId`), invoice (`invoiceNumber`), atau paket tidak ditemukan pada scope aplikasi. | Pastikan external customer ID sudah dibuat via `POST /customers`. |
| `409 Conflict` | Konflik idempotensi (misal `Idempotency-Key` sama dikirim ulang dengan payload berbeda). | Gunakan `Idempotency-Key` baru untuk transaksi baru. |
| `422 Unprocessable` | Input tidak valid: `period_count` di luar rentang 1–12, `due_at` masa lalu, atau nominal IDR memiliki angka desimal/sen. | Periksa rincian `errors` pada JSON response dan perbaiki data input. |
| `429 Too Many Requests` | Melewati rate limiter integrasi (default 120 req/menit). | Hormati header `Retry-After` dan gunakan exponential backoff. |
| `502 Bad Gateway` | Gateway DANA Sandbox sedang mengalami gangguan atau timeout koneksi. | Lakukan retry secara aman dengan menyertakan `Idempotency-Key` yang identik. |

---

## 10. Panduan Integrasi Backend (Laravel)

### 10.1. Konfigurasi Environment (`config/services.php`)

Kredensial integrasi hanya disimpan di backend server Larissama (`.env`):

```dotenv
UDEV_BILLING_URL=https://billing.udev.test
UDEV_INTEGRATION_TOKEN=udev_app_live_xxxxxxxxxxxxxxxxxxxxxxxx
```

Konfigurasi di `config/services.php`:

```php
'udev' => [
    'base_url' => env('UDEV_BILLING_URL', 'https://billing.udev.test'),
    'integration_token' => env('UDEV_INTEGRATION_TOKEN'),
    'token' => env('UDEV_CUSTOMERS_TOKEN'),
],
```

---

### 10.2. Service Client: `app/Services/UdevBillingClient.php`

```php
<?php

namespace App\Services;

use App\Actions\Udev\UdevCustomerConsentNotice;
use App\Models\User;
use App\Models\Warung;
use App\Models\WarungCustomerSync;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class UdevBillingClient
{
    private string $baseUrl;
    private string $token;

    public function __construct()
    {
        $this->baseUrl = (string) (config('services.udev.base_url') ?? '');
        $this->token = (string) (config('services.udev.integration_token') ?? config('services.udev.token') ?? '');
    }

    public function isConfigured(): bool
    {
        return ! empty($this->baseUrl) && ! empty($this->token);
    }

    private function http(): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Integrasi UDEV Billing belum dikonfigurasi di Larissama.');
        }

        return Http::baseUrl(rtrim($this->baseUrl, '/').'/api/v1/integrations')
            ->withToken($this->token)
            ->acceptJson()
            ->asJson()
            ->timeout(15);
    }

    public function getPlans(): array
    {
        $response = $this->http()->get('/subscription-plans');

        return $this->unwrap($response);
    }

    public function ensureCustomer(Warung $warung, User $owner): WarungCustomerSync
    {
        $sync = WarungCustomerSync::query()->where('warung_id', $warung->id)->first();
        if ($sync === null) {
            $sync = WarungCustomerSync::query()->create([
                'warung_id' => $warung->id,
                'owner_user_id' => $owner->id,
                'external_customer_id' => 'larissama-'.Str::ulid(),
                'status' => 'pending',
                'notice_version' => UdevCustomerConsentNotice::VERSION,
                'consented_at' => now(),
            ]);
        }

        if ($sync->status !== 'synced') {
            try {
                $response = $this->http()->post('/customers', [
                    'external_customer_id' => $sync->external_customer_id,
                    'name' => $warung->nama,
                    'owner_name' => $owner->nama,
                    'email' => $owner->email,
                    'phone' => $warung->telepon,
                    'status' => 'active',
                ]);
                if ($response->successful()) {
                    $sync->update(['status' => 'synced', 'last_success_at' => now()]);
                }
            } catch (\Throwable) {
                // Biarkan tetap menggunakan sync record yang ada jika terjadi kendala jaringan sementara
            }
        }

        return $sync;
    }

    public function previewInvoice(string $externalCustomerId, string $planCode, int $periodCount = 1): array
    {
        $response = $this->http()->post("/customers/{$externalCustomerId}/invoice-preview", [
            'subscription_plan_code' => $planCode,
            'period_count' => $periodCount,
        ]);

        return $this->unwrap($response);
    }

    public function createInvoice(
        string $externalCustomerId,
        string $planCode,
        int $periodCount = 1,
        ?string $idempotencyKey = null,
        ?string $dueAt = null
    ): array {
        $idempotencyKey ??= 'laris-inv-'.$externalCustomerId.'-'.Str::uuid();
        $dueAt ??= now()->addDays(2)->toIso8601String();

        $response = $this->http()
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->post("/customers/{$externalCustomerId}/invoices", [
                'subscription_plan_code' => $planCode,
                'period_count' => $periodCount,
                'due_at' => $dueAt,
            ]);

        return $this->unwrap($response);
    }

    public function checkoutInvoice(
        string $invoiceNumber,
        ?string $idempotencyKey = null,
        string $paymentMethod = 'qris'
    ): array {
        $idempotencyKey ??= 'laris-chk-'.$invoiceNumber.'-'.Str::uuid();

        $response = $this->http()
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->post("/invoices/{$invoiceNumber}/checkout", [
                'payment_method' => $paymentMethod,
            ]);

        return $this->unwrap($response);
    }

    public function getInvoice(string $invoiceNumber): array
    {
        $response = $this->http()->get("/invoices/{$invoiceNumber}");

        return $this->unwrap($response);
    }

    public function getCustomerSubscription(string $externalCustomerId): array
    {
        $response = $this->http()->get("/customers/{$externalCustomerId}/subscription");

        return $this->unwrap($response);
    }

    private function unwrap(Response $response): array
    {
        if (! $response->successful()) {
            throw new RuntimeException(
                $response->json('message') ?? 'Permintaan ke UDEV Billing gagal dengan status '.$response->status(),
                $response->status()
            );
        }

        return $response->json('data') ?? [];
    }
}
```

---

### 10.3. Controller Internal Larissama: `SubscriptionPaymentController.php`

Controller ini mengamankan akses pembelian langganan warung (hanya role `owner`), menjembatani panggilan ke UDEV Billing, serta menyinkronkan `tanggal_berakhir` warung saat pembayaran invoice berstatus `paid`:

```php
// Route API di routes/api.php
Route::middleware(['auth:sanctum'])->prefix('v1/subscription')->group(function () {
    Route::get('current', [SubscriptionPaymentController::class, 'current']);
    Route::post('preview', [SubscriptionPaymentController::class, 'preview']);
    Route::post('order', [SubscriptionPaymentController::class, 'order']);
    Route::get('status/{invoiceNumber}', [SubscriptionPaymentController::class, 'status']);
    Route::post('retry/{invoiceNumber}', [SubscriptionPaymentController::class, 'retry']);
});
```

> [!NOTE]
> Pada middleware `EnsureActiveAccount.php`, route dengan prefix `api.v1.subscription.*` dikecualikan dari pemblokiran akun kedaluwarsa sehingga pemilik warung yang masa aktifnya habis dapat langsung memperpanjang langganan.

---

## 11. Panduan Integrasi Frontend (Quasar Framework / Vue 3)

### 11.1. Service API Klien: `src/services/larisama-api.js`

```javascript
import { api } from 'boot/axios';

export const subscriptionService = {
  getCurrent() {
    return api.get('/api/v1/subscription/current').then(r => r.data.data);
  },
  preview(planCode, periodCount) {
    return api.post('/api/v1/subscription/preview', {
      subscription_plan_code: planCode,
      period_count: periodCount,
    }).then(r => r.data.data);
  },
  order(planCode, periodCount, paymentMethod = 'qris') {
    return api.post('/api/v1/subscription/order', {
      subscription_plan_code: planCode,
      period_count: periodCount,
      payment_method: paymentMethod,
    }).then(r => r.data.data);
  },
  getStatus(invoiceNumber) {
    return api.get(`/api/v1/subscription/status/${invoiceNumber}`).then(r => r.data.data);
  },
  retry(invoiceNumber, paymentMethod = 'qris') {
    return api.post(`/api/v1/subscription/retry/${invoiceNumber}`, {
      payment_method: paymentMethod,
    }).then(r => r.data.data);
  },
};
```

---

### 11.2. Alur Render QRIS & Polling Status

Untuk merender QRIS secara lokal di browser Quasar:
1. Pasang library pembentuk QR: `pnpm add qrcode`.
2. Gunakan `QRCode.toDataURL(payload, options)` untuk mengubah string `qr_content` menjadi gambar format PNG Data URL:

```javascript
import QRCode from 'qrcode';

async function generateQrImage(qrPayload) {
  return await QRCode.toDataURL(qrPayload, {
    width: 280,
    margin: 2,
    color: { dark: '#0b1d3a', light: '#ffffff' },
  });
}
```

3. **Mekanisme Polling & Countdown Timer**:
   - Saat sesi checkout QRIS dibuka, mulai countdown timer berdurasi sesuai `checkout_expires_at`.
   - Jalankan interval polling status setiap 3.5 detik ke endpoint status invoice.
   - Ketika status invoice berubah menjadi `paid`:
     - Hentikan polling dan countdown timer.
     - Tampilkan notifikasi sukses dan perbarui tampilan masa aktif warung.
   - Jika waktu habis sebelum pembayaran selesai (`expired`):
     - Tampilkan status kedaluwarsa pada dialog.
     - Sediakan tombol **"Coba lagi / Buat QR Baru"** yang memanggil endpoint `retry` untuk mendapatkan kode QR baru tanpa membuat invoice baru.

---

## 12. Checklist Audit Pra-Deployment Larissama

- [x] **Global Integration Token**: Token terpasang di environment backend server dan terisolasi dari browser.
- [x] **Metode Pembayaran QRIS**: Mendukung checkout QRIS DANA Sandbox dan rendering QR lokal via `qrcode`.
- [x] **Periode 1–12 Bulan**: Validasi backend dan slider UI membatasi `period_count` hanya antara 1 hingga 12.
- [x] **Kalkulasi 30 Hari**: Total hari aktif dihitung tepat $30 \times \text{period\_count}$.
- [x] **Kedaulatan Nominal**: Nominal invoice dihitung dari harga dasar paket dikalikan jumlah periode oleh UDEV.
- [x] **Pencegahan Invoice Ganda**: Header `Idempotency-Key` diterapkan di setiap pembuatan invoice dan sesi checkout.
- [x] **Keabsahan Settlement**: Invoice hanya menjadi `paid` setelah konfirmasi gateway DANA terverifikasi resmi.
- [x] **Retensi Sisa Masa Aktif**: Perpanjangan dan pergantian paket mengakumulasikan seluruh sisa hari aktif pelanggan.
- [x] **Kegagalan & Kedaluwarsa Terisolasi**: Sesi QR yang expired atau pembayaran yang gagal di DANA Sandbox tidak memotong atau mengubah subscription pelanggan.
- [x] **Integritas Modul**: Modul gateway `dana-payment` dan konfigurasi environment production tidak diubah.


