# ATURAN BISNIS & KONTRAK SUBSCRIPTION
## UDEV CENTRALIZED BILLING ↔ LARISSAMA

---

## 1. Prinsip Pengelolaan Terpusat

Di dalam ekosistem Udumbara, seluruh masa aktif pelanggan, perhitungan hak akses, dan status langganan dikelola secara **otoritatif dan terpusat oleh UDEV Centralized Billing**:

1. **Satu Sumber Kebenaran (Single Source of Truth)**:
   - Database UDEV menyimpan status resmi (`active`, `expired`, `cancelled`) dan tanggal kedaluwarsa (`expires_at`).
   - Aplikasi Larissama **tidak boleh** memanipulasi atau memperpanjang masa aktif warung secara lokal (seperti action perpanjangan manual tanpa invoice) tanpa melalui pencatatan di UDEV.
2. **Durasi Berbasis Hari Kalender**:
   - 1 periode berlangganan bernilai tetap **30 hari kalender**.
   - Pelanggan dapat membeli durasi 1 sampai 12 periode (`period_count: 1` s/d `12`) dalam 1 invoice penagihan (misal: 3 periode = 90 hari kalender).
3. **Masa Tenggang Pembayaran (Grace Period)**:
   - Jika warung telah kedaluwarsa (`expires_at` telah lewat), sistem Larissama memberikan masa tenggang pembayaran (30 hari) agar pemilik warung tetap dapat masuk ke dashboard, melihat katalog, dan membayar invoice perpanjangan.

---

## 2. Aturan Perhitungan Masa Aktif (Expiration Rules)

Perhitungan tanggal kedaluwarsa baru saat pembayaran terverifikasi dijalankan oleh `SubscriptionService` di UDEV dengan aturan sebagai berikut:

### Skenario A: Aktivasi Pertama Kali (First Activation)
Berlaku untuk warung baru yang belum pernah memiliki langganan aktif sebelumnya.

- **Formula**:
  - `starts_at = verified_paid_at`
  - `expires_at = verified_paid_at + (30 hari × period_count)`
  - `status = 'active'`
- **Contoh Kasus**:
  - Warung membayar invoice 1 periode pada tanggal **10 November 2026, 14:00:00 WIB**.
  - `starts_at`: `2026-11-10T07:00:00Z`
  - `expires_at`: `2026-12-10T07:00:00Z` (Aktif hingga 10 Desember 2026).

---

### Skenario B: Perpanjangan Saat Paket Masih Aktif (Active Renewal)
Berlaku jika pemilik warung melakukan perpanjangan sebelum masa aktif sebelumnya habis.

> [!IMPORTANT]
> **Tidak Ada Sisa Hari yang Hilang**: Seluruh sisa masa aktif sebelumnya dipertahankan secara utuh. Penambahan durasi baru dihitung dari tanggal kedaluwarsa sebelumnya (`previous_expires_at`), BUKAN dari tanggal pembayaran dilakukan.

- **Kondisi**: `status == 'active'` DAN `expires_at > verified_paid_at`.
- **Formula**:
  - `period_start = previous_expires_at`
  - `new_expires_at = previous_expires_at + (30 hari × period_count)`
  - `status = 'active'`
- **Contoh Kasus**:
  - Masa aktif warung saat ini berakhir pada **25 Desember 2026**.
  - Pada tanggal **15 Desember 2026**, pemilik warung membayar invoice perpanjangan 2 periode (60 hari).
  - Waktu mulai periode baru: `2026-12-25`
  - Tanggal kedaluwarsa baru: `2026-12-25 + 60 hari = 23 Februari 2027`.
  - Pemilik warung tetap menikmati sisa 10 hari bulan Desember ditambah 60 hari baru secara penuh.

---

### Skenario C: Perpanjangan Setelah Paket Kedaluwarsa (Expired Renewal)
Berlaku jika warung sudah kedaluwarsa beberapa hari lalu dan baru melakukan pembayaran.

- **Kondisi**: `status == 'expired'` ATAU `expires_at <= verified_paid_at`.
- **Formula**:
  - `starts_at = verified_paid_at`
  - `new_expires_at = verified_paid_at + (30 hari × period_count)`
  - `status = 'active'`
- **Contoh Kasus**:
  - Masa aktif warung telah habis pada **1 November 2026**.
  - Pemilik warung baru membayar perpanjangan 1 periode pada **6 November 2026, 10:00:00 WIB**.
  - Tanggal kedaluwarsa baru dihitung dari waktu bayar (bukan tanggal 1 November):
    `2026-11-06 + 30 hari = 6 Desember 2026`.

---

## 3. Pembelian Paket Berbeda (Plan Upgrade / Switching)

Pelanggan yang sudah memiliki paket aktif diperbolehkan membeli paket langganan yang berbeda:

1. **Pewarisan & Transisi Paket**:
   - Ketika invoice paket baru diselesaikan (`settlement`), ID paket tujuan (`targetPlanId`) akan diperbarui pada langganan pelanggan.
   - Durasi tambahan dihitung dari tanggal kedaluwarsa berjalan jika periode aktif masih berlaku.
2. **Audit Trail Perubahan Paket**:
   - UDEV mencatat riwayat perubahan paket pada tabel `subscription_period_events` dengan mencatat:
     - `previous_plan_id`: ID paket sebelumnya.
     - `new_plan_id`: ID paket baru.
     - `remaining_days`: Sisa hari yang dibawa dari paket sebelumnya.
     - `additional_days`: Hari tambahan dari invoice baru.

---

## 4. Pencegahan Aktivasi Ganda (Idempotency & Concurrency Guard)

Untuk memastikan tidak pernah terjadi penambahan hari ganda akibat webhook berulang, retry jaringan, atau race condition transaksi, UDEV menerapkan 4 lapis perlindungan:

1. **Pessimistic Row Locking (`lockForUpdate`)**:
   Setiap eksekusi settlement mengunci baris `invoices`, `payment_attempts`, dan `subscriptions` di level basis data MySQL dalam satu transaksi atomik.
2. **Tabel Payment Allocation Tunggal**:
   Satu invoice hanya dapat memiliki tepat satu baris di tabel `payment_allocations`. Jika webhook kedua masuk untuk invoice yang sudah memiliki alokasi, settlement langsung mengembalikan respons `alreadySettled: true` tanpa menyentuh masa aktif.
3. **Idempotency Key pada Period Event**:
   Setiap event perpanjangan masa aktif dicatat dengan kunci idempotensi unik berbasis invoice atau payment attempt (`idempotency_key = "renewal:inv:INV-XXX"`). Jika kunci sudah ada, event lama dikembalikan tanpa memodifikasi `expires_at`.
4. **Validasi Kesesuaian Nominal**:
   Jumlah uang yang dilaporkan oleh gateway Midtrans pada webhook wajib sama persis dengan nilai nominal invoice (`gross_amount == invoice.amount`). Jika nominal tidak cocok, settlement ditolak dengan error `amount_or_currency_mismatch`.

---

## 5. Sinkronisasi Masa Aktif ke Backend Larissama

### Rekomendasi Alur Polling & Update di Larissama:

```
[Frontend Warung] ──────────► [Backend Larissama] ──────────► [UDEV Billing API]
     │                               │                               │
     │ 1. Polling status invoice     │                               │
     │    /subscription/status/{num} │                               │
     ├──────────────────────────────►│ 2. GET /invoices/{num}        │
     │                               ├──────────────────────────────►│
     │                               │ 3. Return Invoice + Subscrip  │
     │                               │◄──────────────────────────────┤
     │                               │                               │
     │                               │ [Pengecekan di Larissama]:    │
     │                               │ Jika invoice.status == 'paid' │
     │                               │ - warungs.tanggal_berakhir =  │
     │                               │   subscription.expires_at     │
     │                               │ - warungs.aktif = true        │
     │                               │ - warungs.save()              │
     │ 4. Return status: PAID &      │                               │
     │    masa aktif terbaru         │                               │
     │◄──────────────────────────────┤                               │
```

#### Contoh Data Subscription yang Dikembalikan:
```json
{
  "subscription": {
    "status": "active",
    "plan_code": "BASIC-WARUNG",
    "starts_at": "2026-11-06T10:40:15.000000Z",
    "expires_at": "2026-12-06T10:40:15.000000Z",
    "period_events": [
      {
        "event_type": "renewal",
        "previous_expires_at": "2026-11-06T10:40:15.000000Z",
        "new_expires_at": "2026-12-06T10:40:15.000000Z",
        "transaction_id": "settlement:payment-attempt:104",
        "processed_at": "2026-11-06T10:40:15.000000Z"
      }
    ]
  }
}
```

---

## 6. Penanganan Invoice Kedaluwarsa (Expired Invoices)

1. **Jatuh Tempo Invoice (`due_at`)**:
   - Jika invoice belum dibayar hingga melewati `due_at`, UDEV menandai status invoice sebagai `expired`.
   - Invoice yang expired tidak dapat dibayarkan lagi.
2. **Pilihan Pemulihan untuk Pelanggan**:
   - Pelanggan di frontend diarahkan untuk menekan tombol **"Pesan Ulang Paket"** yang akan memanggil endpoint preview dan penerbitan invoice baru dengan nomor invoice baru.
