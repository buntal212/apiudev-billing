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

## 6. Checkout DANA Sandbox

### `POST /invoices/{invoiceNumber}/checkout`
Menginisialisasi sesi pembayaran dengan gateway DANA Sandbox.

#### Header:
```http
Idempotency-Key: laris-chk-INV-20261026-SALON01-01
```

#### Request Body:
```json
{}
```

#### Mekanisme Kerja:
1. UDEV Billing mencocokkan konfigurasi merchant DANA Sandbox aktif yang dialokasikan untuk Larissama.
2. Membentuk entitas `PaymentAttempt` dan membuat outbox message secara transactional.
3. Menghubungi DANA Sandbox Gateway untuk mendapatkan URL sesi checkout DANA (`checkout_url`).
4. **Status invoice tetap `pending`**. Status invoice **tidak** berubah menjadi `paid` hanya karena sesi checkout berhasil dibuat atau pelanggan diarahkan ke DANA.

#### Contoh Respons (`200 OK` atau `202 Accepted`):
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
      "checkout_status": "ready",
      "checkout_url": "https://sandbox.dana.id/checkout/order-session-uuid-001",
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

> [!TIP]
> **Alur Pengguna**: Arahkan browser pelanggan Larissama ke `checkout_url`. Setelah menyelesaikan input PIN/OTP di portal DANA Sandbox, DANA akan mengalihkan pengguna kembali ke aplikasi Larissama.

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
      "checkout_status": "ready",
      "checkout_url": "https://sandbox.dana.id/checkout/order-session-uuid-001",
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

### Service Client: `app/Services/UdevBillingClient.php`

```php
<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class UdevBillingClient
{
    private string $baseUrl;
    private string $token;

    public function __construct()
    {
        $this->baseUrl = (string) config('services.udev.base_url');
        $this->token = (string) config('services.udev.integration_token');
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl . '/api/v1/integrations')
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
        int $periodCount,
        string $dueAtIso,
        string $idempotencyKey
    ): array {
        $response = $this->http()
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->post("/customers/{$externalCustomerId}/invoices", [
                'subscription_plan_code' => $planCode,
                'period_count' => $periodCount,
                'due_at' => $dueAtIso,
            ]);
        return $this->unwrap($response);
    }

    public function startCheckout(string $invoiceNumber, string $idempotencyKey): array
    {
        $response = $this->http()
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->post("/invoices/{$invoiceNumber}/checkout");
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
        if ($response->failed()) {
            throw new RuntimeException(
                $response->json('message') ?? 'Gagal berkomunikasi dengan UDEV Billing: HTTP ' . $response->status(),
                $response->status()
            );
        }
        return $response->json('data') ?? [];
    }
}
```

---

## 11. Panduan Integrasi Frontend (Quasar Framework / Vue 3)

### Store Pinia: `src/stores/billing.js`

```javascript
import { defineStore } from 'pinia';
import { api } from 'boot/axios';

export const useBillingStore = defineStore('billing', {
  state: () => ({
    plans: [],
    loading: false,
    selectedPlan: null,
    periodCount: 1,
    preview: null,
    currentInvoice: null,
    checkoutUrl: null,
    pollTimer: null,
  }),

  actions: {
    async fetchPlans() {
      this.loading = true;
      try {
        const { data } = await api.get('/api/billing/plans');
        this.plans = data;
      } finally {
        this.loading = false;
      }
    },

    async loadPreview(customerId, planCode, periodCount) {
      const { data } = await api.post(`/api/billing/customers/${customerId}/preview`, {
        plan_code: planCode,
        period_count: periodCount,
      });
      this.preview = data;
    },

    async submitOrderAndCheckout(customerId) {
      this.loading = true;
      try {
        // Backend Larissama membuat invoice ke UDEV Billing dan langsung menginisiasi checkout
        const { data } = await api.post(`/api/billing/customers/${customerId}/order`, {
          plan_code: this.selectedPlan.code,
          period_count: this.periodCount,
        });

        this.currentInvoice = data.invoice;
        this.checkoutUrl = data.checkout_url;

        if (this.checkoutUrl) {
          window.open(this.checkoutUrl, '_blank');
          this.startStatusPolling(this.currentInvoice.invoice_number);
        }
      } finally {
        this.loading = false;
      }
    },

    startStatusPolling(invoiceNumber) {
      if (this.pollTimer) clearInterval(this.pollTimer);

      this.pollTimer = setInterval(async () => {
        const { data } = await api.get(`/api/billing/invoices/${invoiceNumber}`);
        this.currentInvoice = data;

        if (data.status === 'paid' || data.status === 'failed') {
          clearInterval(this.pollTimer);
          this.pollTimer = null;
        }
      }, 4000);
    },

    stopPolling() {
      if (this.pollTimer) {
        clearInterval(this.pollTimer);
        this.pollTimer = null;
      }
    },
  },
});
```

### Komponen Dialog Pembelian: `src/components/SubscriptionCheckoutDialog.vue`

```vue
<template>
  <q-dialog v-model="isOpen" persistent>
    <q-card style="min-width: 480px; max-width: 90vw" class="q-pa-md">
      <q-card-section class="row items-center q-pb-none">
        <div class="text-h6 text-weight-bold">Beli Langganan Larissama</div>
        <q-space />
        <q-btn icon="close" flat round dense v-close-popup />
      </q-card-section>

      <q-card-section class="q-gutter-y-md">
        <!-- Pilihan Paket -->
        <q-select
          v-model="store.selectedPlan"
          :options="store.plans"
          option-label="name"
          label="Pilih Paket"
          outlined
          dense
          @update:model-value="onSelectionChange"
        />

        <!-- Pilihan Periode (1-12 Bulan) -->
        <div>
          <div class="text-caption text-weight-medium q-mb-xs">
            Jumlah Periode (1 Periode = 30 Hari)
          </div>
          <q-slider
            v-model="store.periodCount"
            :min="1"
            :max="12"
            :step="1"
            markers
            snap
            label
            :label-value="`${store.periodCount} Bulan (${store.periodCount * 30} Hari)`"
            color="primary"
            @change="onSelectionChange"
          />
        </div>

        <!-- Rincian Preview Harga -->
        <q-banner v-if="store.preview" rounded class="bg-blue-1 text-dark q-pa-sm">
          <div class="row justify-between text-caption q-mb-xs">
            <span>Harga Paket:</span>
            <span>Rp {{ formatRupiah(store.preview.pricing.unit_price) }} / 30 hari</span>
          </div>
          <div class="row justify-between text-caption q-mb-xs">
            <span>Durasi Total:</span>
            <span>{{ store.preview.plan.duration_days }} Hari ({{ store.periodCount }} Periode)</span>
          </div>
          <q-separator class="q-my-xs" />
          <div class="row justify-between text-subtitle2 text-weight-bold">
            <span>Total Tagihan:</span>
            <span class="text-primary">Rp {{ formatRupiah(store.preview.pricing.final_price) }}</span>
          </div>
        </q-banner>
      </q-card-section>

      <q-card-actions align="right" class="q-pt-md">
        <q-btn flat label="Batal" color="grey" v-close-popup />
        <q-btn
          unelevated
          color="primary"
          icon="payment"
          label="Bayar via DANA Sandbox"
          :loading="store.loading"
          :disable="!store.selectedPlan || store.loading"
          @click="onCheckoutClick"
        />
      </q-card-actions>
    </q-card>
  </q-dialog>
</template>

<script setup>
import { ref } from 'vue';
import { useBillingStore } from 'src/stores/billing';

const props = defineProps({
  customerId: { type: String, required: true },
});

const store = useBillingStore();
const isOpen = ref(false);

function onSelectionChange() {
  if (store.selectedPlan) {
    store.loadPreview(props.customerId, store.selectedPlan.code, store.periodCount);
  }
}

async function onCheckoutClick() {
  await store.submitOrderAndCheckout(props.customerId);
}

function formatRupiah(num) {
  return Number(num || 0).toLocaleString('id-ID');
}
</script>
```

---

## 12. Checklist Audit Pra-Deployment Larissama

- [x] **Global Integration Token**: Token terpasang di environment backend server dan terisolasi dari browser.
- [x] **Periode 1–12 Bulan**: Validasi backend dan slider UI membatasi `period_count` hanya antara 1 hingga 12.
- [x] **Kalkulasi 30 Hari**: Total hari aktif dihitung tepat $30 \times \text{period\_count}$.
- [x] **Kedaulatan Nominal**: Nominal invoice dihitung dari harga dasar paket dikalikan jumlah periode.
- [x] **Pencegahan Invoice Ganda**: Header `Idempotency-Key` diterapkan di setiap pembuatan invoice dan sesi checkout.
- [x] **Keabsahan Settlement**: Invoice hanya menjadi `paid` setelah konfirmasi gateway DANA terverifikasi resmi.
- [x] **Retensi Sisa Masa Aktif**: Perpanjangan dan pergantian paket mengakumulasikan seluruh sisa hari aktif pelanggan.
- [x] **Kegagalan Terisolasi**: Pembayaran yang gagal atau dibatalkan di DANA Sandbox tidak memotong atau mengubah subscription pelanggan.
- [x] **Integritas Modul**: Modul gateway `dana-payment` dan konfigurasi environment production tidak diubah.

<script setup>
import { ref } from 'vue';
import { useBillingStore } from 'src/stores/billing';

const props = defineProps({
  customerId: { type: String, required: true },
});

const store = useBillingStore();
const isOpen = ref(false);

function onSelectionChange() {
  if (store.selectedPlan) {
    store.loadPreview(props.customerId, store.selectedPlan.code, store.periodCount);
  }
}

async function onCheckoutClick() {
  await store.submitOrderAndCheckout(props.customerId);
}

function formatRupiah(num) {
  return Number(num || 0).toLocaleString('id-ID');
}
</script>
```

---

## 12. Checklist Audit Pra-Deployment Larissama

- [x] **Global Integration Token**: Token terpasang di environment backend server dan terisolasi dari browser.
- [x] **Periode 1–12 Bulan**: Validasi backend dan slider UI membatasi `period_count` hanya antara 1 hingga 12.
- [x] **Kalkulasi 30 Hari**: Total hari aktif dihitung tepat $30 \times \text{period\_count}$.
- [x] **Kedaulatan Nominal**: Nominal invoice dihitung dari harga dasar paket dikalikan jumlah periode.
- [x] **Pencegahan Invoice Ganda**: Header `Idempotency-Key` diterapkan di setiap pembuatan invoice dan sesi checkout.
- [x] **Keabsahan Settlement**: Invoice hanya menjadi `paid` setelah konfirmasi gateway DANA terverifikasi resmi.
- [x] **Retensi Sisa Masa Aktif**: Perpanjangan dan pergantian paket mengakumulasikan seluruh sisa hari aktif pelanggan.
- [x] **Kegagalan Terisolasi**: Pembayaran yang gagal atau dibatalkan di DANA Sandbox tidak memotong atau mengubah subscription pelanggan.
- [x] **Integritas Modul**: Modul gateway `dana-payment` dan konfigurasi environment production tidak diubah.

