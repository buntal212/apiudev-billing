# PANDUAN METODE PEMBAYARAN MIDTRANS

## UDEV CENTRALIZED BILLING ↔ LARISSAMA

---

## 1. Arsitektur Pembayaran Midtrans di UDEV

Di dalam UDEV Centralized Billing, integrasi Midtrans Core API menangani siklus checkout pembayaran secara terenkripsi dan non-intrusif:

1. **Backend UDEV memegang Kredensial**: _Server Key_ Midtrans disimpan terenkripsi menggunakan algoritma `AES-256-GCM` di basis data UDEV. Aplikasi Larissama **tidak pernah** berhubungan langsung dengan _Server Key_.
2. **Kompabilitas Metode**: Checkout dilakukan melalui endpoint UDEV:
   `POST /api/v1/integrations/invoices/{invoiceNumber}/checkout`
   dengan mengirimkan `provider: "midtrans"` dan parameter `payment_method`.
3. **Instruksi Siap Tampil**: UDEV mengembalikan objek `payment_instruction` terstruktur yang dapat langsung dirender oleh antarmuka frontend Larissama.

---

## 2. Matriks Dukungan Kode vs Aktivasi Merchant

> [!IMPORTANT]
> Terdapat perbedaan penting antara **Dukungan Kode di UDEV** dan **Aktivasi Merchant di Portal Midtrans**.
> Kode UDEV sudah mendukung 7 metode pembayaran di bawah ini. Namun pada operasional riil (terutama Production), setiap metode pembayaran harus berstatus **Active** di _Midtrans Merchant Dashboard (Settings > Payment Methods)_ merchant yang bersangkutan.

| Metode Pembayaran           | `payment_method` Code            | Dukungan Kode UDEV | Syarat di Midtrans Merchant                      | Format Output Utama                                         | Batas Waktu Default |
| --------------------------- | -------------------------------- | ------------------ | ------------------------------------------------ | ----------------------------------------------------------- | ------------------- |
| **QRIS Nasional**           | `qris`                           | **TERDUKUNG**      | Acquirer GoPay / LinkAja / ShopeePay / BCA aktif | `qr_content` (EMVCo String) & `checkout_url` (Image QR)     | 15 Menit            |
| **BCA Virtual Account**     | `va_bca` / `bank: "bca"`         | **TERDUKUNG**      | BCA VA aktif di portal Midtrans                  | `payment_code` (Nomor VA BCA)                               | 24 Jam              |
| **BNI Virtual Account**     | `va_bni` / `bank: "bni"`         | **TERDUKUNG**      | BNI VA aktif di portal Midtrans                  | `payment_code` (Nomor VA BNI)                               | 24 Jam              |
| **BRI Virtual Account**     | `va_bri` / `bank: "bri"`         | **TERDUKUNG**      | BRIVA aktif di portal Midtrans                   | `payment_code` (Nomor BRIVA)                                | 24 Jam              |
| **Permata Virtual Account** | `va_permata` / `bank: "permata"` | **TERDUKUNG**      | Permata VA aktif di portal Midtrans              | `payment_code` (Nomor VA Permata)                           | 24 Jam              |
| **Mandiri Bill Payment**    | `va_mandiri` / `echannel`        | **TERDUKUNG**      | Mandiri E-Channel aktif di portal Midtrans       | `biller_code` (Kode Perusahaan) + `bill_key` (Kode Tagihan) | 24 Jam              |
| **GoPay**                   | `gopay`                          | **TERDUKUNG**      | GoPay Direct API aktif di portal Midtrans        | `checkout_url` (Deeplink GoPay) / Fallback QR               | 15 Menit            |
| **Indomaret (CStore)**      | `indomaret` / `cstore`           | **TERDUKUNG**      | Indomaret CStore aktif di portal Midtrans        | `payment_code` (Kode Pembayaran Indomaret)                  | 24 Jam              |

---

## 3. Spesifikasi Rinci Per Metode Pembayaran

### 3.1. QRIS (Quick Response Code Indonesian Standard)

Metode ini memungkinkan pelanggan warung membayar menggunakan aplikasi e-wallet (GoPay, OVO, DANA, ShopeePay, LinkAja) atau mobile banking manapun yang mendukung scan QRIS.

#### Request Checkout:

```json
{
  "provider": "midtrans",
  "payment_method": "qris",
  "idempotency_key": "chk-qris-inv-001"
}
```

#### Struktur Response `payment`:

```json
{
  "status": "pending",
  "provider": "midtrans",
  "payment_method": "qris",
  "bank": null,
  "amount": "100000.00",
  "checkout_status": "ready",
  "checkout_url": "https://api.sandbox.midtrans.com/v2/qris/midtrans-tx-001/qr-code",
  "qr_content": "00020101021226590014ID.LINKAJA.WWW0118936009110022201980020300051440014ID.GO-PAY.WWW0215ID10200210081700303UMI5204581253033605405500005802ID5914UDEV Midtrans6007JAKARTA61051234062070703A0163041D3A",
  "payment_code": null,
  "payment_instruction": {
    "type": "qris_mpm",
    "qr_content": "00020101021226590014ID.LINKAJA.WWW0118...",
    "qr_url": "https://api.sandbox.midtrans.com/v2/qris/midtrans-tx-001/qr-code",
    "checkout_url": "https://api.sandbox.midtrans.com/v2/qris/midtrans-tx-001/qr-code",
    "expiry_time": "2026-11-06T10:50:00.000000Z",
    "expires_at": "2026-11-06T10:50:00.000000Z",
    "transaction_id": "midtrans-tx-001",
    "message": "Pindai kode QRIS Midtrans Sandbox menggunakan aplikasi e-wallet atau mobile banking."
  },
  "checkout_expires_at": "2026-11-06T10:50:00.000000Z",
  "transaction_id": "INV-202611-0001-A1"
}
```

#### Implementasi Frontend Larissama (`depan-larissama`):

1. **Render Gambar QR**:
   - Pilihan A (Dianjurkan): Render string `payment.qr_content` menggunakan library JavaScript lokal seperti `qrcode` (`QRCode.toDataURL(qr_content)`) agar gambar QR tajam dan tidak bergantung pada CDN gambar eksternal.
   - Pilihan B: Tampilkan tag `<img :src="payment.checkout_url" alt="QRIS Code" />`.
2. **Fitur Pendukung**:
   - Sediakan tombol **"Unduh QR"** dan tombol **"Salin Payload QRIS"**.
   - Tampilkan **Countdown Timer** berdasarkan `checkout_expires_at` (15 menit).
   - Jalankan polling status setiap 3–5 detik ke endpoint `/subscription/status/{invoiceNumber}`.

---

### 3.2. BCA Virtual Account

Pelanggan melakukan transfer ke nomor rekening virtual BCA melalui BCA Mobile (m-BCA), KlikBCA (Internet Banking), myBCA, atau ATM BCA.

#### Request Checkout:

```json
{
  "provider": "midtrans",
  "payment_method": "va_bca",
  "idempotency_key": "chk-vabca-inv-001"
}
```

#### Struktur Response `payment`:

```json
{
  "status": "pending",
  "provider": "midtrans",
  "payment_method": "va_bca",
  "bank": "bca",
  "amount": "100000.00",
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
    "transaction_id": "midtrans-tx-002",
    "message": "Bayar melalui Virtual Account BCA sebelum batas waktu."
  },
  "checkout_expires_at": "2026-11-07T10:35:00.000000Z",
  "transaction_id": "INV-202611-0001-A1"
}
```

#### Implementasi Frontend Larissama:

- Tampilkan logo bank **BCA** dan badge `Virtual Account`.
- Tampilkan nomor VA (`100889977112233`) dengan tombol **"Salin Nomor VA"** (`navigator.clipboard.writeText`).
- Tampilkan nominal pasti: **Rp100.000** (harus transfer tepat tanpa pembulatan).
- Berikan accordion instruksi langkah transfer:
  - _m-BCA_: Buka m-BCA > m-Transfer > BCA Virtual Account > Masukkan nomor VA > Konfirmasi & Masukkan PIN.
  - _ATM BCA_: Transaksi Lainnya > Transfer > Ke Rek BCA Virtual Account > Masukkan nomor VA > Bayar.

---

### 3.3. BNI Virtual Account

Pembayaran melalui BNI Mobile Banking, BNI Direct, Internet Banking BNI, atau ATM BNI.

#### Request Checkout:

```json
{
  "provider": "midtrans",
  "payment_method": "va_bni",
  "idempotency_key": "chk-vabni-inv-001"
}
```

#### Output:

- `payment.payment_code`: Nomor VA BNI (contoh: `9888899771122330`).
- `payment.bank`: `"bni"`.
- `payment.payment_instruction.bank`: `"BNI"`.
- Waktu kedaluwarsa: 24 jam.

---

### 3.4. BRI Virtual Account (BRIVA)

Pembayaran melalui aplikasi BRImo, Internet Banking BRI, AgenBRILink, atau ATM BRI.

#### Request Checkout:

```json
{
  "provider": "midtrans",
  "payment_method": "va_bri",
  "idempotency_key": "chk-vabri-inv-001"
}
```

#### Output:

- `payment.payment_code`: Nomor BRIVA (contoh: `123458899771122`).
- `payment.bank`: `"bri"`.
- `payment.payment_instruction.bank`: `"BRI"`.
- Waktu kedaluwarsa: 24 jam.

---

### 3.5. Permata Virtual Account

Pembayaran melalui PermataMobile X, PermataNet, atau transfer antar bank (ATM Bersama / Prima / ALTO) dengan kode bank Permata `013`.

#### Request Checkout:

```json
{
  "provider": "midtrans",
  "payment_method": "va_permata",
  "idempotency_key": "chk-vapermata-inv-001"
}
```

#### Output:

- `payment.payment_code`: Nomor VA Permata (contoh: `8444889977112233`).
- `payment.bank`: `"permata"`.
- `payment.payment_instruction.bank`: `"PERMATA"`.

---

### 3.6. Mandiri Bill Payment (E-Channel)

> [!CAUTION]
> Khusus Bank Mandiri, sistem **tidak** menggunakan nomor virtual account konvensional biasa, melainkan skema **Mandiri Bill Payment (Multi-Payment / E-Channel)** yang membutuhkan **DUA KODE**:
>
> 1. **Kode Perusahaan / Biller Code**: Kode tetap institusi/merchant (contoh: `70012`).
> 2. **Nomor Pembayaran / Bill Key**: Kode tagihan unik pelanggan (contoh: nomor ponsel atau order id).

#### Request Checkout:

```json
{
  "provider": "midtrans",
  "payment_method": "va_mandiri",
  "idempotency_key": "chk-mandiri-inv-001"
}
```

_(Catatan: `payment_method: "echannel"` juga diterima)._

#### Struktur Response `payment`:

```json
{
  "status": "pending",
  "provider": "midtrans",
  "payment_method": "va_mandiri",
  "bank": "mandiri",
  "amount": "100000.00",
  "checkout_status": "ready",
  "payment_code": "081234567890",
  "payment_instruction": {
    "type": "virtual_account",
    "bank": "MANDIRI",
    "payment_code": "081234567890",
    "bill_key": "081234567890",
    "biller_code": "70012",
    "expiry_time": "2026-11-07T10:35:00.000000Z",
    "expires_at": "2026-11-07T10:35:00.000000Z",
    "transaction_id": "midtrans-tx-006",
    "message": "Bayar melalui Virtual Account MANDIRI sebelum batas waktu."
  },
  "checkout_expires_at": "2026-11-07T10:35:00.000000Z",
  "transaction_id": "INV-202611-0001-A1"
}
```

#### Implementasi Frontend Wajib untuk Mandiri:

Frontend Larissama **wajib menampilkan 2 field salin**:

1. Field 1: **Kode Perusahaan (Biller Code)**: `70012` [Tombol Salin]
2. Field 2: **Nomor Pembayaran (Bill Key)**: `081234567890` [Tombol Salin]

- Instruksi Livin' by Mandiri: Menu Bayar > Cari Penyedia Jasa / Masukkan Biller Code `70012` > Masukkan Nomor Pelanggan / Bill Key > Konfirmasi Tagihan > Masukkan PIN.

---

### 3.7. GoPay (Deeplink & QR)

Pembayaran langsung melalui aplikasi GoPay / Gojek di smartphone atau scan QR jika dibuka di komputer.

#### Request Checkout:

```json
{
  "provider": "midtrans",
  "payment_method": "gopay",
  "idempotency_key": "chk-gopay-inv-001"
}
```

#### Struktur Response `payment`:

```json
{
  "status": "pending",
  "provider": "midtrans",
  "payment_method": "gopay",
  "bank": null,
  "amount": "100000.00",
  "checkout_status": "ready",
  "checkout_url": "https://simulator.sandbox.midtrans.com/gopay/partner/app/payment-pin?id=gopay-tx-99",
  "payment_code": null,
  "payment_instruction": {
    "type": "gopay",
    "checkout_url": "https://simulator.sandbox.midtrans.com/gopay/partner/app/payment-pin?id=gopay-tx-99",
    "expiry_time": "2026-11-06T10:50:00.000000Z",
    "expires_at": "2026-11-06T10:50:00.000000Z",
    "transaction_id": "gopay-tx-99",
    "message": "Lanjutkan pembayaran di aplikasi GoPay."
  },
  "checkout_expires_at": "2026-11-06T10:50:00.000000Z",
  "transaction_id": "INV-202611-0001-A1"
}
```

#### Implementasi Frontend Larissama:

- Di browser mobile: Tampilkan tombol utama **"Buka Aplikasi GoPay"** yang mengarahkan pelanggan ke `payment.checkout_url`.
- Di browser desktop: Midtrans akan mengembalikan QR code GoPay yang dapat dipindai langsung dari smartphone.

---

### 3.8. Indomaret (Over-The-Counter / CStore)

Pembayaran tunai atau non-tunai di seluruh gerai Indomaret di Indonesia melalui kasir menggunakan kode pembayaran 10-16 digit.

#### Request Checkout:

```json
{
  "provider": "midtrans",
  "payment_method": "indomaret",
  "idempotency_key": "chk-indomaret-inv-001"
}
```

_(Catatan: `payment_method: "cstore"` juga diterima)._

#### Struktur Response `payment`:

```json
{
  "status": "pending",
  "provider": "midtrans",
  "payment_method": "indomaret",
  "bank": null,
  "amount": "105000.00",
  "subtotal_amount": 100000,
  "admin_fee": 5000,
  "checkout_status": "ready",
  "payment_code": "1029384756",
  "payment_instruction": {
    "type": "cstore",
    "store": "indomaret",
    "payment_code": "1029384756",
    "expiry_time": "2026-11-09T23:59:59.000000Z",
    "expires_at": "2026-11-09T23:59:59.000000Z",
    "transaction_id": "midtrans-tx-cstore-001",
    "message": "Tunjukkan kode pembayaran ke kasir Indomaret sebelum batas waktu berakhir.",
    "instructions": [
      "Kunjungi gerai Indomaret terdekat.",
      "Sampaikan kepada kasir ingin melakukan pembayaran Midtrans / UDEV Invoice.",
      "Tunjukkan kode pembayaran: 1029384756.",
      "Bayar sesuai total tagihan invoice dan simpan struk pembayaran."
    ]
  },
  "fee_breakdown": {
    "package_name": "Paket Langganan",
    "payment_method": "indomaret",
    "payment_method_name": "Indomaret",
    "fee_type": "fixed",
    "fee_rate": 0,
    "fee_amount": 5000,
    "subtotal_amount": 100000,
    "total_amount": 105000
  },
  "checkout_expires_at": "2026-11-09T23:59:59.000000Z",
  "transaction_id": "INV-202611-0001-A1"
}
```

#### Implementasi Frontend Larissama (`depan-larissama`):

- Tampilkan badge / ikon **Gerai Indomaret**.
- Tampilkan **Kode Pembayaran** (`payment_code`) dengan ukuran huruf besar dan tombol **"Salin Kode Bayar"**.
- Tampilkan rincian biaya: **Subtotal Tagihan**, **Biaya Admin (Rp5.000)** terpisah, dan **Total Pembayaran**.
- Tampilkan daftar langkah-langkah pembayaran di kasir:
  1. Datang ke kasir gerai Indomaret terdekat.
  2. Sampaikan ingin membayar transaksi Midtrans / UDEV Invoice.
  3. Berikan kode pembayaran kepada kasir.
  4. Kasir memverifikasi nama merchant dan nominal total tagihan.
  5. Selesaikan pembayaran dan simpan struk fisik Indomaret sebagai bukti sah.
- Pasang polling status setiap 3-5 detik ke endpoint status invoice UDEV.

---

## 4. Siklus Status Transaksi Midtrans

Diagram alur perubahan status transaksi pembayaran di UDEV:

```
                  ┌────────────────────────┐
                  │        PENDING         │
                  │ (Menunggu Pembayaran)  │
                  └───────────┬────────────┘
                              │
            ┌─────────────────┴─────────────────┐
            │ Webhook Midtrans                  │
            ▼                                   ▼
┌────────────────────────┐         ┌────────────────────────┐
│  SETTLEMENT / CAPTURE  │         │  EXPIRE / CANCEL / DENY│
│     (Pembayaran Sah)   │         │ (Kedaluwarsa / Ditolak)│
└───────────┬────────────┘         └───────────┬────────────┘
            │                                   │
            ▼                                   ▼
┌────────────────────────┐         ┌────────────────────────┐
│  STATUS INVOICE: PAID  │         │ STATUS ATTEMPT: FAILED │
│   SUBSCRIPTION ACTIVE  │         │ INVOICE TETAP BISA     │
│   KOMISI TERCATAT      │         │ DIRETRY PEMBAYARANNYA  │
└────────────────────────┘         └────────────────────────┘
```

1. **Pending**: Transaksi telah dibuat, instruksi bayar telah diterbitkan.
2. **Settlement**: Dana berhasil masuk rekening penampung Midtrans. UDEV secara otomatis mengubah invoice menjadi `paid` dan memperpanjang masa aktif warung.
3. **Expire / Cancel**: Batas waktu habis. Payment attempt ditandai `expired` / `cancelled`. Pelanggan dapat menekan tombol **"Ulangi Pembayaran (Retry)"** untuk mendapatkan nomor VA atau QRIS baru pada invoice yang sama.
