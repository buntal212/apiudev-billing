# DAFTAR KODE ERROR & STRATEGI RESOLUSI
## UDEV CENTRALIZED BILLING ↔ LARISSAMA

---

## 1. Standar Format Respons Error UDEV

Semua endpoint integrasi UDEV mengembalikan struktur respons JSON error yang konsisten sesuai jenis kegagalan:

### Format Error Validasi Input (HTTP 422)
```json
{
  "message": "Data yang dikirim belum valid. Periksa kembali isian Anda.",
  "errors": {
    "subscription_plan_code": [
      "Paket aktif tidak ditemukan pada aplikasi ini."
    ],
    "due_at": [
      "Tanggal jatuh tempo harus setelah waktu penerbitan invoice."
    ]
  }
}
```

### Format Error Konflik Bisnis (HTTP 409)
```json
{
  "message": "Pelanggan sudah memiliki kode referral yang terkunci.",
  "error_code": "referral_already_locked"
}
```

### Format Error Akses & Umum (HTTP 401 / 403 / 404 / 429 / 500)
```json
{
  "message": "Deskripsi pesan kesalahan yang ramah pengguna.",
  "error_code": "global_token_required",
  "request_id": "req_672bc19a3b04c"
}
```

---

## 2. Katalog Kode Status HTTP & Solusinya

### 2.1. HTTP 400 Bad Request
Format parameter request rusak, JSON sintaks tidak valid, atau header yang dibutuhkan tidak sesuai spesifikasi.
- **Penyebab**: Body JSON tidak dapat di-parse, karakter ilegal pada parameter URL.
- **Tindakan Larissama**: Perbaiki format serialisasi JSON di client sebelum mencoba kembali. Jangan lakukan retry otomatis.

---

### 2.2. HTTP 401 Unauthorized
Autentikasi gagal pada layer middleware `integration.auth`.

| Pesan / Kode Error | Penyebab | Solusi Tim Larissama |
|---|---|---|
| `"Token integrasi tidak valid."` (`missing_token`) | Header `Authorization` tidak dikirim atau tidak berformat `Bearer <TOKEN>`. | Pastikan header `Authorization: Bearer <TOKEN>` disertakan pada setiap request. |
| `"Token integrasi tidak valid."` (`invalid_token`) | Nilai token salah atau tidak terdaftar di database UDEV. | Periksa konfigurasi `UDEV_CUSTOMERS_TOKEN` di file `.env` Larissama. |
| `"Token integrasi sudah dicabut."` (`token_revoked`) | Token telah dicabut (revoked) oleh Administrator UDEV. | Hubungi Administrator UDEV untuk menerbitkan token baru. |
| `"Token integrasi sudah kedaluwarsa."` (`token_expired`) | Masa berlaku token integrasi telah habis. | Lakukan rotasi token integrasi. |

---

### 2.3. HTTP 403 Forbidden
Otorisasi ditolak pada layer middleware `integration.global` atau pengecekan tenant scope.

| Pesan / Kode Error | Penyebab | Solusi Tim Larissama |
|---|---|---|
| `"Global API Token aplikasi diperlukan."` (`global_token_required`) | Token yang digunakan berjenis scoped token, bukan `global` token. | Gunakan Global Integration Token aplikasi Larissama. |
| `"Aplikasi tidak aktif untuk integrasi."` (`application_inactive`) | Status aplikasi Larissama di UDEV sedang dinonaktifkan (`is_active = false`). | Hubungi Administrator UDEV untuk mengaktifkan status aplikasi. |

---

### 2.4. HTTP 404 Not Found
Resource yang diminta tidak ditemukan dalam lingkup tenant Larissama.

| Pesan Error | Penyebab | Solusi Tim Larissama |
|---|---|---|
| `"Pelanggan eksternal tidak ditemukan."` | `externalId` belum pernah disinkronisasi ke UDEV. | Jalankan `POST /customers` terlebih dahulu sebelum preview/create invoice. |
| `"Paket aktif tidak ditemukan pada aplikasi ini."` | `subscription_plan_code` salah atau milik aplikasi lain. | Ambil kode paket terkini dari `GET /subscription-plans`. |
| `"Invoice tidak ditemukan pada aplikasi ini."` | Nomor invoice tidak ada atau milik tenant lain. | Periksa kembali nomor invoice yang diminta. |

---

### 2.5. HTTP 409 Conflict
Pelanggaran aturan integritas bisnis atau operasi duplikat yang bertentangan.

| Pesan / Kode Error | Penyebab | Solusi Tim Larissama |
|---|---|---|
| `"Pelanggan sudah memiliki kode referral yang terkunci."` (`referral_already_locked`) | Mencoba mengaitkan kode referral pada warung yang sudah memiliki diskon terkunci. | Jangan panggil endpoint attach referral ulang. Lanjutkan ke preview invoice. |
| `"Invoice sudah memiliki settlement."` (`invoice_already_settled`) | Mencoba membatalkan atau mengubah invoice yang sudah lunas. | Tidak ada tindakan yang diperlukan; invoice sudah berstatus `paid`. |

---

### 2.6. HTTP 422 Unprocessable Entity
Gagal validasi parameter pada Laravel Form Request.

| Kasus Validasi | Pesan Validasi | Solusi |
|---|---|---|
| `due_at` masa lalu | `"Tanggal jatuh tempo harus setelah waktu penerbitan invoice."` | Berikan tanggal jatuh tempo minimal 1 hari ke depan. |
| Durasi di luar range | `"Jumlah periode harus antara 1 dan 12."` | Batasi slider periode warung antara 1 sampai 12. |
| Parameter terlarang dikirim | `The amount field is prohibited.` / `The application_id field is prohibited.` | Hapus field terlarang (`amount`, `currency`, `application_id`, `subscription_id`, `merchant`). |
| Format Idempotency Key salah | `The idempotency key format is invalid.` | Gunakan string alfanumerik `[A-Za-z0-9._-]{1,64}`. |
| Tagihan pending duplikat | `"Tagihan pending untuk kewajiban yang sama sudah tersedia."` | Gunakan invoice pending yang sudah ada atau tunggu hingga kedaluwarsa. |

---

### 2.7. HTTP 429 Too Many Requests
Permintaan melampaui batas rate limit integrasi (`throttle:integration-api`).
- **Header Respons**:
  - `Retry-After`: Jumlah detik yang **wajib** ditunggu sebelum mengirim request berikutnya.
  - `X-RateLimit-Limit`: Kuota maksimum request per menit.
  - `X-RateLimit-Remaining`: Sisa kuota request yang tersedia.
- **Pesan**: `"Terlalu banyak permintaan. Silakan tunggu beberapa saat sebelum mencoba kembali."`
- **Solusi**: Larissama wajib membaca header `Retry-After`, menunda pengiriman request selama nilai tersebut, dan menerapkan exponential backoff.

---

### 2.8. HTTP 500 / 502 / 503 / 504 Gateway & Server Error
Kesalahan internal server atau kegagalan komunikasi antara UDEV dengan Midtrans.
- **Pesan 500**: `"Layanan sedang mengalami gangguan. Silakan coba kembali."`
- **Pesan 502 / 504**: Gateway timeout saat memanggil Midtrans Core API.

---

## 3. Matriks Keputusan: Kapan Boleh Retry vs Kapan Wajib Cek Status?

> [!CAUTION]
> Pada transaksi finansial pembayaran, melakukan *blind retry* (mengulang request tanpa memeriksa status) dapat menyebabkan duplikasi charge atau kebingungan pengguna. Ikuti panduan baku berikut:

```
                  Apakah Request Anda Gagal?
                              │
               ┌──────────────┴──────────────┐
               ▼                             ▼
       Error 4xx (Client)            Error 5xx / Timeout / Network
               │                             │
    ┌──────────┴──────────┐                  │
    ▼                     ▼                  ▼
Error 422 / 401 / 404   Error 429     Apakah operasi berupa CHECKOUT?
(Jangan Retry Langsung, (Tunggu Detik        │
 Perbaiki Parameter)     Retry-After)  ┌─────┴─────┐
                                       ▼           ▼
                                      TIDAK        YA
                                  (Safe Retry)     │
                                                   ▼
                                       ┌─────────────────────────┐
                                       │ WAJIB CEK STATUS DULU:  │
                                       │ GET /invoices/{num}     │
                                       └───────────┬─────────────┘
                                                   │
                            ┌──────────────────────┴──────────────────────┐
                            ▼                                             ▼
                 Jika Status Sudah READY                        Jika Belum Ada Attempt /
               (Gunakan Data VA/QR Tersebut)                     Status FAILED
                                                                       │
                                                                       ▼
                                                          Boleh Retry Checkout dengan
                                                          IDEMPOTENCY-KEY YANG SAMA
```

### 1. Kategori SAFE TO RETRY (Boleh Langsung Ulangi):
- `GET /subscription-plans`
- `GET /customers/{id}`
- `GET /invoices/{invoiceNumber}`
- `GET /customers/{id}/subscription`
- `POST /customers/{id}/invoice-preview`
- Request yang menghasilkan HTTP `429` (setelah masa tunggu `Retry-After` terpenuhi).

### 2. Kategori CONDITIONAL RETRY (Wajib Cek Status Dahulu):
- **Saat `POST /invoices/{invoiceNumber}/checkout` mengalami timeout atau 502/504**:
  1. Lakukan `GET /invoices/{invoiceNumber}`.
  2. Periksa apakah `data.payment.checkout_status === 'ready'`.
  3. Jika **ready**, artinya Midtrans sudah berhasil membuat transaksi dan mengembalikan nomor VA/QR. Tampilkan instruksi tersebut ke pelanggan.
  4. Jika **belum ready atau failed**, panggil kembali endpoint checkout dengan menyertakan **`Idempotency-Key` yang sama persis**.
