# DOKUMENTASI RESMI INTEGRASI SISTEM
# UDEV CENTRALIZED BILLING ↔ LARISSAMA (MIDTRANS)

---

## 1. Overview Integrasi

Dokumen ini adalah spesifikasi dan panduan teknis resmi bagi tim pengembang aplikasi **Larissama** untuk mengintegrasikan proses penagihan, pembayaran paket langganan warung, dan perpanjangan masa aktif melalui sistem **UDEV Centralized Billing** dengan menggunakan **Midtrans Payment Gateway** sebagai gateway pembayaran utama.

### Mengapa Menggunakan UDEV Centralized Billing?
1. **Pusat Rekonsiliasi & Keuangan**: Seluruh transaksi langganan, masa aktif warung, dan pembagian komisi mitra referral dikelola terpusat oleh UDEV.
2. **Abstraksi Payment Gateway**: Backend Larissama tidak berurusan langsung dengan kredensial rahasia Midtrans (*Server Key*). Larissama cukup berkomunikasi dengan UDEV Billing API menggunakan satu *Global Integration Token*.
3. **Pemisahan Lingkungan Aman**: Perpindahan antara mode *Sandbox* dan *Production* dikelola di UDEV tanpa mewajibkan perubahan kode atau deployment berulang pada sisi Larissama.

---

## 2. Arsitektur Sistem

Arsitektur integrasi menghubungkan tiga lapisan sistem:

```
┌─────────────────┐         ┌───────────────────────┐         ┌───────────────────┐         ┌─────────────────┐
│                 │  HTTPS  │                       │  HTTPS  │                   │  HTTPS  │                 │
│    LARISSAMA    ├────────►│     UDEV BILLING      ├────────►│  mitrans-payment  ├────────►│    MIDTRANS     │
│ (Front & Back)  │ Bearer  │   Central Platform    │ Service │   Microservice    │ CoreAPI │ Payment Gateway │
│                 │◄────────┤ (apiudev.udumbara...) │◄────────┤ (mitrans.udumbara)│◄────────┤                 │
└─────────────────┘         └───────────┬───────────┘         └───────────────────┘ Webhook └─────────────────┘
                                        │
                             Settlement │ Webhook & Verify
                                        ▼
                            ┌───────────────────────┐
                            │      MySQL Live       │
                            │  Invoices, Customers, │
                            │ Subscriptions, Ledgers│
                            └───────────────────────┘
```

### Komponen Sistem:
1. **Larissama (`depan-larissama` & `api.larissama`)**:
   - Menampilkan katalog paket langganan ke pemilik warung.
   - Menginisiasi pesanan langganan dan meminta sesi checkout ke UDEV.
   - Menampilkan instruksi pembayaran (QRIS, Nomor Virtual Account, atau Deeplink GoPay) kepada pemilik warung.
   - Memperbarui status masa aktif warung lokal (`tanggal_berakhir` & `aktif`) berdasarkan data tersinkronisasi dari UDEV.
2. **UDEV Centralized Billing (`apiudev-billing`)**:
   - Mengelola katalog paket berlangganan (`subscription_plans`).
   - Menyimpan data pemetaan pelanggan (`application_customers`).
   - Menerbitkan invoice penagihan resmi dengan kalkulasi diskon referral otomatis (`invoices`).
   - Mengelola payment attempts dan outbox checkout idempoten (`payment_attempts`, `checkout_outbox_messages`).
   - Memverifikasi webhook Midtrans (SHA512 Signature Key), melakukan alokasi pembayaran (`payment_allocations`), memperpanjang masa aktif langganan (`subscriptions`), dan mencatat komisi mitra (`referral_commission_ledgers`).
3. **Midtrans Microservice (`mitrans-payment`)**:
   - Microservice internal UDEV bertugas memproksi komunikasi API Core Midtrans dan menangani payload webhook awal sebelum diteruskan ke UDEV Billing.
   - Domain: `https://mitrans.udumbara.my.id`.
4. **Midtrans Payment Gateway Engine**:
   - Memproses pembayaran via jaringan QRIS nasional, bank switching (BCA, BNI, BRI, Permata, Mandiri Bill), dan GoPay.

---

## 3. Pembagian Tanggung Jawab (RACI Matrix)

| Area Fungsional | UDEV Centralized Billing | Larissama Backend | Larissama Frontend |
|---|---|---|---|
| **Katalog Paket & Durasi** | Menentukan paket, harga dasar, dan durasi resmi (kelipatan 30 hari). | Mengambil katalog via API UDEV dan memvalidasi otorisasi owner. | Menampilkan grid kartu paket langganan. |
| **Data Pelanggan (Warung)** | Menyimpan customer mapping terisolasi per aplikasi. | Mengirim sinkronisasi warung via `POST /customers`. | Menyediakan profil warung pemilik akun. |
| **Kalkulasi Biaya & Diskon** | Menghitung harga akhir, diskon referral terkunci, dan tanggal jatuh tempo. | Meminta preview via `POST .../invoice-preview`. | Menampilkan rincian invoice dan slider periode (1–12). |
| **Penerbitan Invoice** | Menerbitkan nomor invoice resmi, mengunci status `pending`. | Meminta pembuatan invoice via `POST .../invoices`. | Menampilkan informasi invoice pending. |
| **Kredensial Midtrans** | Menyimpan & mengenkripsi Server Key (AES-256-GCM). Larissama dilarang mengakses Server Key. | Tidak menyimpan kredensial Midtrans. | Tidak memanggil Midtrans secara langsung. |
| **Inisiasi Checkout** | Menghubungi Midtrans Core API dan mengembalikan instruksi pembayaran. | Meneruskan permintaan checkout via `POST .../checkout`. | Mengirim pilihan metode pembayaran (QRIS / VA / GoPay). |
| **Tampilan Instruksi Bayar** | Mengembalikan `qr_content`, `payment_code`, `biller_code`, `checkout_url`. | Memformat data instruksi untuk frontend. | Menampilkan QR code, tombol copy VA, countdown timer, dan panduan transfer. |
| **Verifikasi Pembayaran** | Menerima webhook notifikasi Midtrans, validasi signature SHA512, eksekusi settlement idempoten. | Melakukan polling `GET .../invoices/{num}` atau query status. | Menampilkan status lunas dan tombol selesai. |
| **Perpanjangan Masa Aktif** | Memperpanjang masa aktif subscription pelanggan (`starts_at`, `expires_at`). | Mengambil data subscription terbaru dan mengupdate `warungs.tanggal_berakhir`. | Menampilkan badge aktif dan sisa hari berlangganan. |
| **Komisi Referral Mitra** | Menghitung komisi mitra (Rp50.000 pertama, Rp7.500 renewal), audit ledger, payout. | Mengirim kode referral saat pendaftaran warung. | Menampilkan input kode referral saat register warung. |

---

## 4. Persyaratan Teknis Integrasi

1. **Protokol Jaringan**: Seluruh komunikasi wajib menggunakan **HTTPS** dengan TLS 1.2 atau lebih tinggi. Request via HTTP plaintext akan ditolak.
2. **Format Data**:
   - Header `Content-Type: application/json` wajib disertakan pada seluruh request dengan body (`POST`, `PATCH`, `PUT`).
   - Header `Accept: application/json` wajib disertakan pada seluruh request.
3. **Idempotency Key**:
   - Operasi pembuatan invoice dan checkout wajib menyertakan kunci idempotensi melalui header `Idempotency-Key` atau body parameter `idempotency_key`.
   - Format yang didukung: String alfanumerik `[A-Za-z0-9._-]{1,64}`.
4. **Timeouts & Retry**:
   - Timeout koneksi HTTP client di Larissama disarankan: `connect_timeout = 3s`, `timeout = 10s`.
   - Gunakan strategi **exponential backoff with jitter** jika terjadi timeout atau status `429 Too Many Requests`.

---

## 5. Konfigurasi Environment

UDEV menyediakan pemisahan lingkungan yang ketat antara Sandbox dan Production:

| Parameter | Sandbox (Pengujian) | Production (Operasional Nyata) |
|---|---|---|
| **UDEV Base URL** | `https://staging-apiudev.udumbara.my.id` (atau mock dev `https://apiudev.udumbara.my.id`) | `https://apiudev.udumbara.my.id` |
| **Canonical Prefix** | `/api/v1/integrations` | `/api/v1/integrations` |
| **Compatibility Prefix** | `/api/v1/integration` | `/api/v1/integration` |
| **Mitrans Gateway URL** | `https://mitrans.udumbara.my.id` | `https://mitrans.udumbara.my.id` |
| **Midtrans Core API** | `https://api.sandbox.midtrans.com/v2` | `https://api.midtrans.com/v2` |
| **Simulator Pembayaran** | Simulator QRIS / VA Midtrans Sandbox | Rekening Bank Riil / E-Wallet Riil |
| **Nominal Uji Coba** | Bebas / Virtual | Uang Nyata |

> [!IMPORTANT]
> Jangan pernah menggunakan kredensial Sandbox di Production atau sebaliknya. Pengalihan dari Sandbox ke Production di sisi UDEV hanya dapat dilakukan melalui otorisasi khusus Administrator UDEV dengan konfirmasi ganda.

---

## 6. Autentikasi (Global Integration Token)

Setiap panggilan API dari Larissama ke UDEV wajib menyertakan **Global Integration Token** milik aplikasi Larissama pada header HTTP Authorization:

```http
Authorization: Bearer udev_66d1fc2ec130b18a25e63a566bbaa5b95e660e2ae510b2a1999d7937ab2af65e
Accept: application/json
```

### Prinsip Keamanan Token:
- **Server-to-Server Only**: Token ini adalah kredensial tingkat tinggi yang mewakili aplikasi Larissama. Simpan token ini hanya di file `.env` atau secret manager backend `api.larissama`.
- **Dilarang Keras Bocor ke Frontend**: Jangan pernah menyertakan token ini pada aplikasi Vue/Quasar (`depan-larissama`), script browser, public repository, atau log aplikasi.
- **Tenant Isolation Otomatis**: UDEV secara otomatis mengidentifikasi ID aplikasi (`application_id = 1`) dari hash token ini. Larissama **dilarang mengirimkan `application_id`** pada request body/parameter.

---

## 7. Siklus Alur Integrasi (Developer Journey)

Berikut adalah urutan langkah standar dalam memproses transaksi pembayaran langganan:

```
[1. Sync Warung] ──► [2. Load Katalog] ──► [3. Preview Invoice] ──► [4. Terbitkan Invoice]
                                                                           │
[8. Update Warung] ◄── [7. Poll Status] ◄── [6. Tampilkan Bayar] ◄── [5. Checkout Midtrans]
```

1. **Sinkronisasi Pelanggan (`POST /customers`)**:
   Saat warung mendaftar atau menyetujui integrasi, daftarkan `external_customer_id` (misal: ID Warung `WARUNG-001`) ke UDEV.
2. **Katalog Paket (`GET /subscription-plans`)**:
   Ambil daftar paket berlangganan resmi yang aktif untuk aplikasi Larissama.
3. **Kalkulasi Biaya (`POST /customers/{externalId}/invoice-preview`)**:
   Hitung total tagihan dan diskon berdasarkan jumlah periode yang dipilih pelanggan (1 s/d 12 bulan) tanpa membuat record transaksi di database.
4. **Penerbitan Invoice (`POST /customers/{externalId}/invoices`)**:
   Terbitkan invoice tagihan berstatus `pending` dengan kunci idempotensi unik.
5. **Inisiasi Checkout Midtrans (`POST /invoices/{invoiceNumber}/checkout`)**:
   Kirim parameter `provider = "midtrans"` beserta metode yang dipilih (contoh: `payment_method = "va_bca"`).
6. **Tampilkan Instruksi Pembayaran ke Pelanggan**:
   Frontend menampilkan nomor VA / QR code / link pembayaran beserta batas waktu pembayaran.
7. **Pengecekan Status (`GET /invoices/{invoiceNumber}`)**:
   Frontend melakukan polling berkala ke backend Larissama yang meneruskannya ke UDEV untuk memeriksa apakah invoice sudah berstatus `paid`.
8. **Sinkronisasi Masa Aktif Warung (`GET /customers/{externalId}/subscription`)**:
   Setelah pembayaran terverifikasi, Larissama mengambil tanggal kedaluwarsa terbaru (`expires_at`) dan memperbarui kolom `tanggal_berakhir` pada tabel warung lokal.

---

## 8. Penanganan Error & Retry Safety

| Kondisi | Tindakan yang Diperbolehkan | Tindakan yang Dilarang |
|---|---|---|
| **HTTP 422 Validation Error** | Perbaiki isian body sesuai pesan error pada field `errors`. | Jangan retry berulang tanpa perbaikan parameter. |
| **HTTP 429 Rate Limited** | Baca header `Retry-After` dan tunggu durasi detik yang ditentukan sebelum mencoba lagi. | Jangan membanjiri request tanpa jeda backoff. |
| **HTTP 502 / 504 / Connection Timeout saat Checkout** | **WAJIB** panggil `GET /invoices/{invoiceNumber}` terlebih dahulu untuk memeriksa apakah payment attempt terbentuk. Jika attempt sudah ada dan ready, gunakan data tersebut. Jika gagal, panggil ulang checkout dengan **Idempotency-Key yang sama**. | **DILARANG** membuat invoice baru sebelum memastikan status invoice sebelumnya. |
| **Batas Waktu Pembayaran Habis (Expired)** | Panggil checkout ulang pada invoice yang sama atau buat invoice baru jika invoice utama telah expired. | Jangan membiarkan pelanggan mentransfer ke VA yang kedaluwarsa. |

---

## 9. Struktur Dokumen & Referensi Lanjutan

Untuk detail spesifikasi masing-masing bagian, silakan pelajari dokumen terpisah berikut:

- 📑 [KONTRAK API LENGKAP (API-CONTRACT.md)](file:///F:/buntal/udumbara/apiudev-billing/docs/integrations/larissama-midtrans/API-CONTRACT.md)
- 💳 [METODE PEMBAYARAN MIDTRANS (MIDTRANS-PAYMENT.md)](file:///F:/buntal/udumbara/apiudev-billing/docs/integrations/larissama-midtrans/MIDTRANS-PAYMENT.md)
- 🔄 [ATURAN SUBSCRIPTION & MASA AKTIF (SUBSCRIPTION-CONTRACT.md)](file:///F:/buntal/udumbara/apiudev-billing/docs/integrations/larissama-midtrans/SUBSCRIPTION-CONTRACT.md)
- 🤝 [KONTRAK REFERRAL & KOMISI (REFERRAL-CONTRACT.md)](file:///F:/buntal/udumbara/apiudev-billing/docs/integrations/larissama-midtrans/REFERRAL-CONTRACT.md)
- ⚠️ [DAFTAR KODE ERROR & RESOLUSI (ERROR-CODES.md)](file:///F:/buntal/udumbara/apiudev-billing/docs/integrations/larissama-midtrans/ERROR-CODES.md)
- 💻 [CONTOH IMPLEMENTASI LARAVEL CLIENT (INTEGRATION-EXAMPLES.md)](file:///F:/buntal/udumbara/apiudev-billing/docs/integrations/larissama-midtrans/INTEGRATION-EXAMPLES.md)
- 📊 [DIAGRAM URUTAN TRANSAKSI (PAYMENT-FLOW.md)](file:///F:/buntal/udumbara/apiudev-billing/docs/integrations/larissama-midtrans/PAYMENT-FLOW.md)
- 🚦 [LAPORAN KESIAPAN INTEGRASI (INTEGRATION-READINESS.md)](file:///F:/buntal/udumbara/apiudev-billing/docs/integrations/larissama-midtrans/INTEGRATION-READINESS.md)
- 📦 [KONTRAK FORMAL OPENAPI 3.1 (openapi.yaml)](file:///F:/buntal/udumbara/apiudev-billing/docs/integrations/larissama-midtrans/openapi.yaml)
- 📮 [POSTMAN COLLECTION V2.1 (UDEV-Larissama-Midtrans.postman_collection.json)](file:///F:/buntal/udumbara/apiudev-billing/docs/integrations/larissama-midtrans/UDEV-Larissama-Midtrans.postman_collection.json)
- ⚙️ [POSTMAN ENVIRONMENT (UDEV-Larissama.postman_environment.json)](file:///F:/buntal/udumbara/apiudev-billing/docs/integrations/larissama-midtrans/UDEV-Larissama.postman_environment.json)
