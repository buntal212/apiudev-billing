# API Pembayaran Larissama → UDEV (DANA Sandbox)

Dokumen ini menjelaskan kontrak machine-to-machine yang sudah diterapkan bagi backend Larissama. Semua path relatif terhadap `{UDEV_API_BASE}/api/v1/integrations` dan hanya untuk DANA Sandbox.

## Keamanan dan isolasi tenant

- Gunakan satu **Global API Token** aktif untuk aplikasi Larissama. Token menentukan aplikasi di server; jangan kirim `application_id`.
- Simpan token hanya di backend/secret manager Larissama, bukan Quasar/Vue, browser, URL, atau log.
- Customer, paket, invoice, transaksi, dan subscription dibatasi oleh aplikasi pemilik token. Data aplikasi lain tidak dapat dibaca.
- Checkout langganan baru selalu dipilih server ke merchant Sandbox pusat `udev-central`. Klien tidak boleh menentukan merchant, nominal, currency, provider, atau subscription ID.
- Tidak ada fallback ke merchant aplikasi. Transaksi historis tidak dipindahkan oleh API ini.
- Tidak ada endpoint integrasi untuk menandai invoice `paid`. Status lunas hanya berasal dari hasil DANA yang diverifikasi UDEV terhadap merchant, reference, nominal, currency, dan environment.

Header dasar:

```http
Authorization: Bearer {GLOBAL_APPLICATION_TOKEN}
Accept: application/json
Content-Type: application/json
```

Token yang bukan `global`, dicabut, kedaluwarsa, atau milik aplikasi nonaktif ditolak.

## Endpoint aktual

| Method | Path | Kegunaan |
| --- | --- | --- |
| `GET` | `/subscription-plans` | Katalog paket aktif Larissama. |
| `POST` | `/customers` | Upsert pelanggan berdasarkan ID eksternal. |
| `GET` | `/customers/{externalId}` | Baca pelanggan tenant sendiri. |
| `PATCH` | `/customers/{externalId}` | Update pelanggan yang sudah ada. |
| `POST` | `/customers/{externalId}/invoice-preview` | Hitung harga/durasi tanpa invoice. |
| `POST` | `/customers/{externalId}/invoices` | Buat invoice idempoten. |
| `POST` | `/invoices/{invoiceNumber}/checkout` | Mulai/ulang checkout DANA Sandbox idempoten. |
| `GET` | `/invoices/{invoiceNumber}` | Status invoice, payment, dan settlement. |
| `GET` | `/customers/{externalId}/transactions` | Riwayat invoice/transaksi. |
| `GET` | `/customers/{externalId}/subscription` | Status paket dan masa aktif. |

Tidak ada endpoint atau tabel baru yang dibuat untuk alur ini.

## Katalog paket

`GET /subscription-plans?per_page=100` mengembalikan hanya paket aktif tenant token.

```json
{
  "data": [{
    "id": 10,
    "code": "LARIS-30",
    "name": "Paket Larissama",
    "description": "Langganan 30 hari",
    "price": "10000.00",
    "currency": "IDR",
    "duration_days": 30,
    "is_active": true
  }]
}
```

Harga adalah string desimal. Larissama memilih `code`; server menentukan harga, currency, dan durasi. Satu periode selalu 30 hari; `period_count` bernilai 1–12.

## Sinkronisasi pelanggan

`POST /customers` melakukan upsert berdasarkan aplikasi dari token + `external_customer_id` yang stabil.

```json
{
  "external_customer_id": "SALON-001",
  "name": "Salon Cantika",
  "owner_name": "Siti Rahma",
  "email": "siti@example.test",
  "phone": "081234567890",
  "status": "active"
}
```

```json
{
  "data": {
    "id": 101,
    "application_id": 3,
    "external_customer_id": "SALON-001",
    "name": "Salon Cantika",
    "owner_name": "Siti Rahma",
    "email": "siti@example.test",
    "phone_number": "081234567890",
    "is_active": true,
    "source": "integration",
    "sync_status": "synced"
  },
  "sync": { "status": "accepted" },
  "request_id": "..."
}
```

Respons baru adalah `201`; upsert ulang adalah `200`. `PATCH /customers/{externalId}` menerima field yang sama secara parsial dan tidak membuat record baru. `GET /customers/{externalId}` hanya membaca tenant pemilik token. Customer manual tidak dapat diambil alih dan menghasilkan `409`.

## Preview dan invoice subscription

Preview tidak menulis data:

```http
POST /customers/SALON-001/invoice-preview
```

```json
{ "subscription_plan_code": "LARIS-30", "period_count": 3 }
```

```json
{
  "data": {
    "customer_external_id": "SALON-001",
    "plan": { "code": "LARIS-30", "name": "Paket Larissama", "duration_days": 90, "period_count": 3 },
    "pricing": { "unit_price": "10000.00", "period_count": 3, "normal_price": "30000.00", "final_price": "30000.00", "currency": "IDR" }
  },
  "request_id": "..."
}
```

Pembuatan invoice harus memakai key idempotensi yang unik untuk satu niat pembelian:

```http
POST /customers/SALON-001/invoices
Idempotency-Key: laris-invoice-SALON-001-20261027-01
```

```json
{
  "subscription_plan_code": "LARIS-30",
  "period_count": 3,
  "due_at": "2026-10-28T12:00:00Z"
}
```

```json
{
  "data": {
    "invoice_number": "INV-20261027-ABC123",
    "status": "pending",
    "period_count": 3,
    "plan": { "code": "LARIS-30", "duration_days": 90, "period_count": 3 },
    "pricing": { "normal_price": "30000.00", "final_price": "30000.00", "currency": "IDR" },
    "payment": null,
    "subscription": null,
    "tracking": { "settlement_status": null }
  },
  "request_id": "..."
}
```

Request ulang dengan header dan payload yang sama tidak membuat invoice kedua. Jangan mengirim `amount`, `currency`, `application_id`, atau `subscription_id`; seluruhnya ditolak.

## Checkout QRIS DANA Sandbox

```http
POST /invoices/INV-20261027-ABC123/checkout
Idempotency-Key: laris-checkout-INV-20261027-ABC123-qris-01
```

```json
{ "payment_method": "qris" }
```

UDEV memilih `udev-central` di server. Respons `200` berarti instruksi siap, sedangkan `202` berarti masih diproses dan dapat dipoll melalui `GET /invoices/{invoiceNumber}`. Invoice tetap `pending` sampai settlement sah.

```json
{
  "data": {
    "invoice_number": "INV-20261027-ABC123",
    "status": "pending",
    "payment": {
      "status": "pending",
      "provider": "dana",
      "payment_method": "qris",
      "checkout_status": "ready",
      "checkout_url": null,
      "qr_content": null,
      "payment_code": "kode-dari-custom-checkout",
      "payment_instruction": { "method": "qris", "type": "payment_code" },
      "checkout_expires_at": "2026-10-27T12:30:00.000000Z",
      "transaction_id": "INV-20261027-ABC123-A1",
      "failure_code": null
    }
  },
  "request_id": "..."
}
```

`payment_code` Custom Checkout bukan otomatis QR Content MPM. Hanya render QR jika `qr_content` benar-benar dikirim oleh kontrak QRIS yang terverifikasi. Jika yang tersedia hanya `payment_code`, tampilkan sebagai kode/instruksi; jangan mengubahnya menjadi QR MPM.

## Polling pembayaran dan masa aktif

Gunakan `GET /invoices/{invoiceNumber}` untuk status invoice/settlement, bukan untuk memalsukan callback gateway.

```json
{
  "data": {
    "invoice_number": "INV-20261027-ABC123",
    "status": "paid",
    "paid_at": "2026-10-27T12:05:00.000000Z",
    "payment": { "status": "succeeded", "payment_method": "qris" },
    "tracking": { "settlement_status": "completed", "settled_at": "2026-10-27T12:05:01.000000Z" }
  }
}
```

Gunakan `GET /customers/SALON-001/subscription` untuk masa aktif:

```json
{
  "data": {
    "customer_external_id": "SALON-001",
    "subscription": {
      "status": "active",
      "starts_at": "2026-09-27T12:00:00.000000Z",
      "expires_at": "2027-01-25T12:00:00.000000Z",
      "plan_code": "LARIS-30"
    }
  },
  "request_id": "..."
}
```

Settlement terverifikasi menambah 30 × `period_count` hari dari `expires_at` yang masih tersisa, atau dari waktu pembayaran jika sudah lewat. Pergantian paket memperbarui paket saat renewal tanpa menghilangkan sisa aktif. Webhook/reconciliation DANA diproses internal UDEV; Larissama tidak memiliki endpoint untuk mengatur `paid`.

`GET /customers/{externalId}/transactions` mengembalikan riwayat resource invoice yang sama dalam pagination (`per_page` 1–100).

## Status error dan retry

| Status | Arti | Tindakan |
| --- | --- | --- |
| `401` | Token tidak valid, dicabut, atau kedaluwarsa. | Hentikan retry dan perbaiki secret. |
| `403` | Token bukan Global API Token atau aplikasi tidak aktif. | Hentikan retry; periksa konfigurasi. |
| `404` | Resource tidak ada dalam tenant token. | Jangan mencoba tenant lain. |
| `409` | Konflik customer/integrasi. | Jangan overwrite otomatis. |
| `422` | Input, periode, jatuh tempo, atau konfigurasi checkout invalid. | Perbaiki input; jangan retry buta. |
| `429` | Rate limit. | Hormati `Retry-After` dan backoff. |
| `5xx`/timeout | Gangguan sementara. | Retry terbatas dengan key idempotensi yang sama. |

Jangan menggunakan key baru saat retry untuk niat pembelian/checkout yang sama. Jangan menganggap redirect browser, pemindaian QR, atau response checkout sebagai bukti pembayaran.

## Contoh Laravel HTTP Client

```php
use Illuminate\Support\Facades\Http;

$client = Http::baseUrl(config('services.udev.base_url'))
    ->acceptJson()
    ->withToken(config('services.udev.larissama_global_token'))
    ->connectTimeout(3)
    ->timeout(10);

$customer = $client->post('/api/v1/integrations/customers', [
    'external_customer_id' => (string) $tenant->id,
    'name' => $tenant->name,
    'owner_name' => $tenant->owner_name,
    'email' => $tenant->email,
    'phone' => $tenant->phone,
    'status' => $tenant->active ? 'active' : 'inactive',
]);
$customer->throw();

$invoice = $client
    ->withHeader('Idempotency-Key', "laris-invoice-{$tenant->id}-{$purchaseId}")
    ->post("/api/v1/integrations/customers/{$tenant->id}/invoices", [
        'subscription_plan_code' => $planCode,
        'period_count' => $periodCount,
        'due_at' => now()->addDay()->toIso8601String(),
    ]);
$invoice->throw();

$number = $invoice->json('data.invoice_number');
$checkout = $client
    ->withHeader('Idempotency-Key', "laris-checkout-{$number}-qris")
    ->post("/api/v1/integrations/invoices/{$number}/checkout", ['payment_method' => 'qris']);

if ($checkout->status() === 202) {
    // Jadwalkan polling GET /invoices/{invoiceNumber} secara terbatas.
} else {
    $checkout->throw();
}
```

Simpan `request_id`, HTTP status, dan error code yang aman untuk audit Larissama. Jangan menyimpan bearer token pada model pelanggan, log, atau respons frontend.
