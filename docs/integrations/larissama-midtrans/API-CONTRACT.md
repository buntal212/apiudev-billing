# KONTRAK RESMI API UDEV CENTRALIZED BILLING

## KHUSUS INTEGRASI EKSTERNAL APLIKASI LARISSAMA

---

## 1. Konvensi Global API

### Base URL & Prefix Rute

- **Prefix Kanonis**: `/api/v1/integrations` (Standar REST API)
- **Prefix Kompatibilitas**: `/api/v1/integration` (Alias legacy yang didukung penuh)
- **Base URL Staging / Mock**: `https://apiudev.udumbara.my.id`
- **Base URL Production**: `https://apiudev.udumbara.my.id`

Seluruh endpoint di bawah ini dijelaskan menggunakan prefix kanonis `/api/v1/integrations`.

### Header Wajib Global

Setiap request ke endpoint integrasi wajib menyertakan header berikut:

```http
Authorization: Bearer <GLOBAL_INTEGRATION_TOKEN>
Accept: application/json
Content-Type: application/json
```

### Format Amplop Respons Standar

Setiap respons sukses dari UDEV dikemas dalam format JSON terstandarisasi:

```json
{
  "data": { ... },
  "request_id": "req_672bc19a3b04c",
  "meta": { ... }
}
```

Format respons error:

```json
{
  "message": "Deskripsi umum kesalahan.",
  "errors": {
    "field_name": ["Detail pesan validasi error..."]
  },
  "error_code": "validation_failed",
  "request_id": "req_672bc19a3b04c"
}
```

---

## 2. Ringkasan Endpoint Integrasi

| Method  | Endpoint Path                             | Deskripsi Singkat                                                         | Status Kode               |
| ------- | ----------------------------------------- | ------------------------------------------------------------------------- | ------------------------- |
| `GET`   | `/subscription-plans`                     | Ambil katalog paket langganan aktif milik Larissama.                      | `200 OK`                  |
| `POST`  | `/customers`                              | Upsert pendaftaran/sinkronisasi profil warung ke UDEV.                    | `200 OK` / `201 Created`  |
| `GET`   | `/customers/{externalId}`                 | Ambil profil pelanggan warung berdasarkan ID eksternal.                   | `200 OK`                  |
| `PATCH` | `/customers/{externalId}`                 | Perbarui data kontak warung yang sudah ada.                               | `200 OK`                  |
| `POST`  | `/customers/{externalId}/invoice-preview` | Hitung simulasi harga, durasi, dan diskon referral tanpa membuat invoice. | `200 OK`                  |
| `POST`  | `/customers/{externalId}/invoices`        | Terbitkan invoice penagihan resmi berstatus `pending`.                    | `201 Created`             |
| `POST`  | `/invoices/{invoiceNumber}/checkout`      | Mulai sesi checkout pembayaran Midtrans (QRIS/VA/GoPay).                  | `200 OK` / `202 Accepted` |
| `GET`   | `/invoices/{invoiceNumber}`               | Baca status terkini invoice, detail instruksi, dan rekonsiliasi.          | `200 OK`                  |
| `GET`   | `/customers/{externalId}/subscription`    | Baca status paket aktif, tanggal mulai, dan kedaluwarsa warung.           | `200 OK`                  |
| `GET`   | `/customers/{externalId}/transactions`    | Ambil riwayat penagihan dan histori payment attempts terpaginasi.         | `200 OK`                  |
| `POST`  | `/referrals/validate`                     | Validasi keberadaan dan status aktif kode referral mitra.                 | `200 OK`                  |
| `POST`  | `/customers/{externalId}/referral`        | Kunci kode referral pada warung saat pendaftaran pertama.                 | `201 Created`             |

---

## 3. Detail Kontrak Endpoint

### 3.1. Get Subscription Plans

Mengambil daftar paket berlangganan resmi yang dikonfigurasi aktif untuk aplikasi Larissama.

- **HTTP Method**: `GET`
- **Endpoint Path**: `/api/v1/integrations/subscription-plans`
- **Otentikasi**: Bearer Token
- **Query Parameters**:
  - `page` _(optional, integer, min: 1)_: Halaman data paginasi (default: 1).
  - `per_page` _(optional, integer, min: 1, max: 100)_: Jumlah item per halaman (default: 15, disarankan 100).

#### Contoh Request:

```http
GET /api/v1/integrations/subscription-plans?per_page=100 HTTP/1.1
Host: apiudev.udumbara.my.id
Authorization: Bearer udev_66d1fc2ec130b18a25e63a566bbaa5b95e660e2ae510b2a1999d7937ab2af65e
Accept: application/json
```

#### Contoh Success Response (`200 OK`):

```json
{
  "data": [
    {
      "id": 1,
      "code": "BASIC-WARUNG",
      "name": "Paket Warung Mandiri",
      "description": "Akses penuh fitur kasir, inventori, laporan penjualan, dan sinkronisasi cloud.",
      "price": "175000.00",
      "currency": "IDR",
      "duration_days": 30,
      "is_active": true
    }
  ],
  "links": {
    "first": "https://apiudev.udumbara.my.id/api/v1/integrations/subscription-plans?page=1",
    "last": "https://apiudev.udumbara.my.id/api/v1/integrations/subscription-plans?page=1",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "per_page": 100,
    "to": 1,
    "total": 1
  }
}
```

---

### 3.2. Upsert Customer (Sinkronisasi Pelanggan Warung)

Mendaftarkan atau memperbarui data profil warung dari Larissama ke UDEV Centralized Billing.

- **HTTP Method**: `POST`
- **Endpoint Path**: `/api/v1/integrations/customers`
- **Otentikasi**: Bearer Token
- **Request Body Fields**:
  - `external_customer_id` _(required, string, max: 100)_: ID unik warung di Larissama (contoh: ID integer warung `WARUNG-12` atau UUID).
  - `name` _(required, string, max: 255)_: Nama usaha/warung.
  - `owner_name` _(required, string, max: 255)_: Nama lengkap pemilik warung.
  - `email` _(required, string, email, max: 255)_: Alamat email pemilik akun warung.
  - `phone` _(required, string, max: 30)_: Nomor telepon/WhatsApp aktif warung.
  - `status` _(optional, string, in: `active`, `suspended`, `inactive`)_: Status operasional akun (default: `active`).

#### Contoh Request:

```http
POST /api/v1/integrations/customers HTTP/1.1
Host: apiudev.udumbara.my.id
Authorization: Bearer udev_66d1fc2ec130b18a25e63a566bbaa5b95e660e2ae510b2a1999d7937ab2af65e
Content-Type: application/json
Accept: application/json

{
  "external_customer_id": "WARUNG-001",
  "name": "Warung Berkah Nusantara",
  "owner_name": "Budi Santoso",
  "email": "budi.santoso@warungberkah.test",
  "phone": "081234567890",
  "status": "active"
}
```

#### Contoh Success Response (`201 Created` / `200 OK`):

```json
{
  "data": {
    "id": 142,
    "application_id": 1,
    "external_customer_id": "WARUNG-001",
    "name": "Warung Berkah Nusantara",
    "owner_name": "Budi Santoso",
    "email": "budi.santoso@warungberkah.test",
    "phone_number": "081234567890",
    "is_active": true,
    "source": "integration",
    "sync_status": "synced"
  },
  "sync": {
    "status": "accepted"
  },
  "request_id": "req_672bc19a3b04c"
}
```

---

### 3.3. Invoice Preview (Simulasi Harga & Durasi)

Menghitung simulasi nominal harga, potongan diskon referral, dan total hari aktif tanpa menerbitkan invoice di database.

- **HTTP Method**: `POST`
- **Endpoint Path**: `/api/v1/integrations/customers/{externalId}/invoice-preview`
- **Path Parameter**:
  - `externalId` _(required, string)_: `external_customer_id` warung yang terdaftar.
- **Request Body Fields**:
  - `subscription_plan_code` _(required, string, max: 100)_: Kode paket (contoh: `BASIC-WARUNG`).
  - `period_count` _(optional, integer, min: 1, max: 12)_: Jumlah bulan/periode yang ingin dibeli (default: 1).
- **Parameter Terlarang**: `application_id`, `amount`, `currency` (Dilarang dikirim; server menghitung harga secara otoritatif).

#### Contoh Request:

```http
POST /api/v1/integrations/customers/WARUNG-001/invoice-preview HTTP/1.1
Host: apiudev.udumbara.my.id
Authorization: Bearer udev_66d1fc2ec130b18a25e63a566bbaa5b95e660e2ae510b2a1999d7937ab2af65e
Content-Type: application/json
Accept: application/json

{
  "subscription_plan_code": "BASIC-WARUNG",
  "period_count": 3
}
```

#### Contoh Success Response (`200 OK`):

```json
{
  "data": {
    "customer_external_id": "WARUNG-001",
    "plan": {
      "code": "BASIC-WARUNG",
      "name": "Paket Warung Mandiri",
      "duration_days": 90,
      "period_count": 3
    },
    "pricing": {
      "unit_price": "175000.00",
      "period_count": 3,
      "normal_price": "525000.00",
      "referral_code": "MITRA-JAKARTA",
      "referral_type": "khusus",
      "discount_amount": "225000.00",
      "final_price": "300000.00",
      "currency": "IDR"
    },
    "available_gateways": [
      {
        "provider": "midtrans",
        "merchant_code": "larissama"
      }
    ]
  },
  "request_id": "req_672bc19a3b04c"
}
```

---

### 3.4. Create Invoice (Penerbitan Invoice Pending)

Menerbitkan invoice penagihan resmi yang siap dibayarkan.

- **HTTP Method**: `POST`
- **Endpoint Path**: `/api/v1/integrations/customers/{externalId}/invoices`
- **Path Parameter**:
  - `externalId` _(required, string)_: `external_customer_id` warung.
- **Header Khusus**:
  - `Idempotency-Key` _(optional jika ada di body, required string, max: 100)_.
- **Request Body Fields**:
  - `subscription_plan_code` _(required, string, max: 100)_: Kode paket aktif.
  - `due_at` _(required, string ISO8601/date)_: Batas waktu jatuh tempo pembayaran invoice. **Wajib lebih besar dari waktu sekarang**.
  - `period_count` _(optional, integer, min: 1, max: 12)_: Durasi periode (default: 1).
  - `idempotency_key` _(required jika header tidak ada, string, max: 100, regex `/^[A-Za-z0-9][A-Za-z0-9._-]*$/`)_.
- **Parameter Terlarang**: `application_id`, `amount`, `currency`, `subscription_id`.

#### Contoh Request:

```http
POST /api/v1/integrations/customers/WARUNG-001/invoices HTTP/1.1
Host: apiudev.udumbara.my.id
Authorization: Bearer udev_66d1fc2ec130b18a25e63a566bbaa5b95e660e2ae510b2a1999d7937ab2af65e
Idempotency-Key: inv-order-warung-001-20261106-01
Content-Type: application/json
Accept: application/json

{
  "subscription_plan_code": "BASIC-WARUNG",
  "due_at": "2026-11-08T23:59:59Z",
  "period_count": 1,
  "idempotency_key": "inv-order-warung-001-20261106-01"
}
```

#### Contoh Success Response (`201 Created`):

```json
{
  "data": {
    "invoice_number": "INV-202611-0001",
    "status": "pending",
    "period_count": 1,
    "issued_at": "2026-11-06T10:30:00.000000Z",
    "due_at": "2026-11-08T23:59:59.000000Z",
    "paid_at": null,
    "plan": {
      "code": "BASIC-WARUNG",
      "name": "Paket Warung Mandiri",
      "duration_days": 30,
      "period_count": 1
    },
    "pricing": {
      "normal_price": "175000.00",
      "referral_code": "MITRA-JAKARTA",
      "referral_type": "khusus",
      "discount_amount": "75000.00",
      "final_price": "100000.00",
      "currency": "IDR"
    },
    "payment": null,
    "payment_history": [],
    "subscription": null,
    "tracking": {
      "settlement_id": null,
      "settlement_status": null,
      "settlement_error_code": null,
      "settled_at": null
    }
  },
  "request_id": "req_672bc19a3b04c"
}
```

---

### 3.5. Checkout Invoice (Inisiasi Pembayaran Midtrans)

Meminta inisiasi sesi pembayaran ke payment gateway Midtrans dan menghasilkan QRIS, Nomor VA, atau link GoPay.

- **HTTP Method**: `POST`
- **Endpoint Path**: `/api/v1/integrations/invoices/{invoiceNumber}/checkout`
- **Path Parameter**:
  - `invoiceNumber` _(required, string)_: Nomor invoice UDEV (contoh: `INV-202611-0001`).
- **Header Khusus**:
  - `Idempotency-Key` _(optional jika di body, string, max: 64)_.
- **Request Body Fields**:
  - `idempotency_key` _(required jika header tidak ada, string, max: 64, regex `/^[A-Za-z0-9][A-Za-z0-9._-]*$/`)_.
  - `provider` _(optional, string, enum: `midtrans`, `dana`)_: **Sangat disarankan selalu mengirimkan `"midtrans"`**.
  - `payment_method` _(optional, string)_:
    - `qris` (QRIS Nasional MPM)
    - `va_bca` (BCA Virtual Account)
    - `va_bni` (BNI Virtual Account)
    - `va_bri` (BRI Virtual Account)
    - `va_permata` (Permata Virtual Account)
    - `va_mandiri` atau `echannel` (Mandiri Bill Payment)
    - `gopay` (GoPay Deeplink / QR)
  - `bank` _(optional, string, in: `bca`, `bni`, `bri`, `permata`, `mandiri`, `echannel`)_: Opsional jika nama bank tidak disatukan di `payment_method`.
- **Parameter Terlarang**: `amount`, `currency`, `merchant`, `merchant_code`.

#### Contoh Request (Checkout BCA Virtual Account):

```http
POST /api/v1/integrations/invoices/INV-202611-0001/checkout HTTP/1.1
Host: apiudev.udumbara.my.id
Authorization: Bearer udev_66d1fc2ec130b18a25e63a566bbaa5b95e660e2ae510b2a1999d7937ab2af65e
Idempotency-Key: chk-warung-001-attempt-01
Content-Type: application/json
Accept: application/json

{
  "provider": "midtrans",
  "payment_method": "va_bca",
  "idempotency_key": "chk-warung-001-attempt-01"
}
```

#### Contoh Success Response (`200 OK` - Ready):

```json
{
  "data": {
    "invoice_number": "INV-202611-0001",
    "status": "pending",
    "period_count": 1,
    "issued_at": "2026-11-06T10:30:00.000000Z",
    "due_at": "2026-11-08T23:59:59.000000Z",
    "paid_at": null,
    "plan": {
      "code": "BASIC-WARUNG",
      "name": "Paket Warung Mandiri",
      "duration_days": 30,
      "period_count": 1
    },
    "pricing": {
      "normal_price": "175000.00",
      "referral_code": "MITRA-JAKARTA",
      "referral_type": "khusus",
      "discount_amount": "75000.00",
      "final_price": "100000.00",
      "currency": "IDR"
    },
    "payment": {
      "status": "pending",
      "provider": "midtrans",
      "payment_method": "va_bca",
      "bank": "bca",
      "amount": "100000.00",
      "subtotal_amount": 100000.0,
      "admin_fee": 0.0,
      "fee_breakdown": null,
      "checkout_status": "ready",
      "checkout_url": null,
      "qr_content": null,
      "payment_code": "100889977112233",
      "payment_instruction": {
        "type": "virtual_account",
        "bank": "BCA",
        "payment_code": "100889977112233",
        "bill_key": null,
        "biller_code": null,
        "expiry_time": "2026-11-07T10:35:00.000000Z",
        "expires_at": "2026-11-07T10:35:00.000000Z",
        "transaction_id": "midtrans-trx-887711",
        "message": "Bayar melalui Virtual Account BCA sebelum batas waktu."
      },
      "checkout_expires_at": "2026-11-07T10:35:00.000000Z",
      "transaction_id": "INV-202611-0001-A1",
      "completed_at": null,
      "failure_code": null
    },
    "payment_history": [
      {
        "attempt_number": 1,
        "provider": "midtrans",
        "status": "pending",
        "payment_method": "va_bca",
        "bank": "bca",
        "amount": "100000.00",
        "currency": "IDR",
        "requested_at": "2026-11-06T10:35:00.000000Z",
        "completed_at": null
      }
    ],
    "subscription": null,
    "tracking": {
      "settlement_id": null,
      "settlement_status": null,
      "settlement_error_code": null,
      "settled_at": null
    }
  },
  "request_id": "req_672bc19a3b04c"
}
```

---

### 3.6. Get Invoice Detail (Cek Status Pembayaran)

Membaca status terkini suatu invoice (digunakan untuk polling setelah pelanggan membayar atau verifikasi rekonsiliasi).

- **HTTP Method**: `GET`
- **Endpoint Path**: `/api/v1/integrations/invoices/{invoiceNumber}`
- **Path Parameter**:
  - `invoiceNumber` _(required, string)_: Nomor invoice.
- **Query Parameters**:
  - `reconcile` _(optional, boolean, `1` / `true`)_: Jika `true` dan invoice masih pending, memicu UDEV memeriksa status pembayaran langsung ke gateway.

#### Contoh Request:

```http
GET /api/v1/integrations/invoices/INV-202611-0001 HTTP/1.1
Host: apiudev.udumbara.my.id
Authorization: Bearer udev_66d1fc2ec130b18a25e63a566bbaa5b95e660e2ae510b2a1999d7937ab2af65e
Accept: application/json
```

#### Contoh Success Response (`200 OK` - Status Lunas / Paid):

```json
{
  "data": {
    "invoice_number": "INV-202611-0001",
    "status": "paid",
    "period_count": 1,
    "issued_at": "2026-11-06T10:30:00.000000Z",
    "due_at": "2026-11-08T23:59:59.000000Z",
    "paid_at": "2026-11-06T10:40:15.000000Z",
    "plan": {
      "code": "BASIC-WARUNG",
      "name": "Paket Warung Mandiri",
      "duration_days": 30,
      "period_count": 1
    },
    "pricing": {
      "normal_price": "175000.00",
      "referral_code": "MITRA-JAKARTA",
      "referral_type": "khusus",
      "discount_amount": "75000.00",
      "final_price": "100000.00",
      "currency": "IDR"
    },
    "payment": {
      "status": "succeeded",
      "provider": "midtrans",
      "payment_method": "va_bca",
      "bank": "bca",
      "amount": "100000.00",
      "subtotal_amount": 100000.0,
      "admin_fee": 0.0,
      "checkout_status": "ready",
      "checkout_url": null,
      "qr_content": null,
      "payment_code": "100889977112233",
      "payment_instruction": {
        "type": "virtual_account",
        "bank": "BCA",
        "payment_code": "100889977112233"
      },
      "checkout_expires_at": "2026-11-07T10:35:00.000000Z",
      "transaction_id": "INV-202611-0001-A1",
      "completed_at": "2026-11-06T10:40:15.000000Z",
      "failure_code": null
    },
    "subscription": {
      "status": "active",
      "plan_code": "BASIC-WARUNG",
      "starts_at": "2026-11-06T10:40:15.000000Z",
      "expires_at": "2026-12-06T10:40:15.000000Z",
      "period_events": [
        {
          "event_type": "activation",
          "previous_expires_at": null,
          "new_expires_at": "2026-12-06T10:40:15.000000Z",
          "transaction_id": "settlement:payment-attempt:104",
          "processed_at": "2026-11-06T10:40:15.000000Z"
        }
      ]
    },
    "tracking": {
      "settlement_id": 89,
      "settlement_status": "completed",
      "settlement_error_code": null,
      "settled_at": "2026-11-06T10:40:15.000000Z"
    }
  },
  "request_id": "req_672bc19a3b04c"
}
```

---

### 3.7. Get Customer Subscription (Status Masa Aktif Pelanggan)

Membaca status paket aktif warung, tanggal mulai, dan tanggal kedaluwarsa resmi dari UDEV.

- **HTTP Method**: `GET`
- **Endpoint Path**: `/api/v1/integrations/customers/{externalId}/subscription`
- **Path Parameter**:
  - `externalId` _(required, string)_: ID eksternal warung di Larissama.

#### Contoh Request:

```http
GET /api/v1/integrations/customers/WARUNG-001/subscription HTTP/1.1
Host: apiudev.udumbara.my.id
Authorization: Bearer udev_66d1fc2ec130b18a25e63a566bbaa5b95e660e2ae510b2a1999d7937ab2af65e
Accept: application/json
```

#### Contoh Success Response (`200 OK`):

```json
{
  "data": {
    "customer_external_id": "WARUNG-001",
    "subscription": {
      "status": "active",
      "starts_at": "2026-11-06T10:40:15.000000Z",
      "expires_at": "2026-12-06T10:40:15.000000Z",
      "plan_code": "BASIC-WARUNG"
    },
    "referral": {
      "code": "MITRA-JAKARTA",
      "type": "khusus",
      "base_price": "175000.00",
      "discount_amount": "75000.00",
      "customer_price": "100000.00",
      "locked_at": "2026-11-01T08:00:00.000000Z"
    }
  },
  "request_id": "req_672bc19a3b04c"
}
```

---

### 3.8. Get Customer Transactions (Riwayat Transaksi Pelanggan)

Mengambil daftar histori seluruh invoice dan payment attempts warung secara terpaginasi.

- **HTTP Method**: `GET`
- **Endpoint Path**: `/api/v1/integrations/customers/{externalId}/transactions`
- **Path Parameter**:
  - `externalId` _(required, string)_: ID eksternal warung.
- **Query Parameters**:
  - `page` _(optional, integer, default: 1)_
  - `per_page` _(optional, integer, min: 1, max: 100, default: 15)_

#### Contoh Response:

Mengembalikan koleksi array berisikan objek invoice yang sama persis dengan skema `IntegrationInvoiceResource`.

---

### 3.9. Validate Referral Code

Memeriksa apakah kode referral mitra valid, aktif, serta mendapatkan rincian besaran diskon.

- **HTTP Method**: `POST`
- **Endpoint Path**: `/api/v1/integrations/referrals/validate`
- **Request Body Fields**:
  - `referral_code` _(required, string, max: 50)_

#### Contoh Request:

```json
{
  "referral_code": "MITRA-JAKARTA"
}
```

#### Contoh Success Response (`200 OK`):

```json
{
  "data": {
    "is_valid": true,
    "code": "MITRA-JAKARTA",
    "type": "khusus",
    "base_price": "175000.00",
    "discount_amount": "75000.00",
    "customer_price": "100000.00"
  },
  "request_id": "req_672bc19a3b04c"
}
```

---

### 3.10. Attach Referral to Customer (Kunci Diskon Pendaftaran Warung)

Mengunci kode referral pada akun warung saat pertama kali mendaftar.

- **HTTP Method**: `POST`
- **Endpoint Path**: `/api/v1/integrations/customers/{externalId}/referral`
- **Request Body Fields**:
  - `referral_code` _(required, string, max: 50)_

#### Contoh Success Response (`201 Created`):

```json
{
  "data": {
    "customer_external_id": "WARUNG-001",
    "referral": {
      "code": "MITRA-JAKARTA",
      "type": "khusus",
      "base_price": "175000.00",
      "discount_amount": "75000.00",
      "customer_price": "100000.00",
      "locked_at": "2026-11-06T10:45:00.000000Z"
    }
  },
  "request_id": "req_672bc19a3b04c"
}
```

_Catatan: Jika warung sudah memiliki referral yang terkunci sebelumnya, endpoint ini mengembalikan HTTP `409 Conflict` dengan pesan `"Pelanggan sudah memiliki kode referral yang terkunci."`_
