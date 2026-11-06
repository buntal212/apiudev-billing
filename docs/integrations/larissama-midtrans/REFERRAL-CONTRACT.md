# KONTRAK BISNIS REFERRAL & KOMISI MITRA
## UDEV CENTRALIZED BILLING ↔ LARISSAMA

---

## 1. Pembagian Batasan Tanggung Jawab

| Domain Tanggung Jawab | Aplikasi Larissama | UDEV Centralized Billing |
|---|---|---|
| **Input Kode Referral** | Menyediakan input kode referral pada form pendaftaran warung baru. | - |
| **Validasi Kode** | Memanggil API UDEV `POST /referrals/validate`. | Memeriksa validitas kode, jenis mitra, dan status aktif. |
| **Penguncian Harga Warung** | Mengirimkan kode saat warung terdaftar via `POST /customers/{id}/referral`. | Mengunci tarif diskon permanen di tabel `customer_referrals`. |
| **Kalkulasi Potongan Harga** | Mengambil harga akhir dari UDEV `invoice-preview`. | Menghitung diskon otomatis per kelipatan periode (1–12 bulan). |
| **Pencatatan Komisi Mitra** | **TIDAK TERLIBAT** (Larissama dilarang mencatat komisi mitra). | Menghitung komisi pertama vs perpanjangan, mencatat audit ledger. |
| **Saldo & Pencairan Mitra** | **TIDAK TERLIBAT** | Mengelola saldo tersedia, saldo tertahan, payout rekening mitra. |

---

## 2. Struktur Harga Paket Berlangganan

Setiap paket langganan dasar berdurasi 30 hari kalender memiliki struktur harga resmi sebagai berikut:

| Kategori Pendaftaran | Harga Dasar (30 Hari) | Besaran Diskon | Harga Akhir Pelanggan | Kelipatan Periode (N Bulan) |
|---|---|---|---|---|
| **Non-Referral (Normal)** | Rp175.000 | Rp0 | **Rp175.000** | Rp175.000 × N |
| **Referral Umum (`umum`)** | Rp175.000 | Rp50.000 | **Rp125.000** | Rp125.000 × N |
| **Referral Khusus (`khusus`)** | Rp175.000 | Rp75.000 | **Rp100.000** | Rp100.000 × N |

### Aturan Perhitungan Multi-Periode:
Jika pelanggan warung memilih durasi lebih dari 1 bulan (slider 1–12 periode):
- **Harga Normal**: `175.000 × period_count`
- **Total Diskon**: `discount_amount × period_count`
- **Total Tagihan**: `customer_price × period_count`
Contoh: Warung dengan Referral Khusus memilih durasi 6 bulan (180 hari):
`Total Tagihan = Rp100.000 × 6 = Rp600.000` (Diskon Rp450.000 dari harga normal Rp1.050.000).

---

## 3. Mekanisme Penguncian Harga (Locked Referral Price)

1. **Permanen Seumur Hidup Akun Warung**:
   - Ketika warung didaftarkan dengan kode referral, data referral dikunci di tabel `customer_referrals`.
   - Data yang terkunci mencakup: `referral_code`, `referral_type`, `base_price`, `discount_amount`, `customer_price`, dan `locked_at`.
2. **Tidak Perlu Input Ulang**:
   - Pada tagihan bulan-bulan berikutnya (perpanjangan), pemilik warung **tidak perlu menginputkan kode referral kembali**.
   - Sistem UDEV secara otomatis mengenali bahwa warung tersebut memiliki harga referral terkunci dan langsung menerapkan tarif diskon pada setiap invoice baru.
3. **Proteksi Anti-Overriding**:
   - Jika suatu warung sudah memiliki referral yang terkunci, pemanggilan kembali endpoint `POST /customers/{id}/referral` akan ditolak oleh UDEV dengan respons HTTP `409 Conflict` (`referral_already_locked`).

---

## 4. Aturan Komisi Mitra (Sisi Internal UDEV)

Informasi ini disediakan agar tim teknis memahami tata kelola keuangan di balik setiap pembayaran sukses:

### Nilai Komisi Tetap:
1. **Pembayaran Sukses Pertama**: **Rp50.000** per warung.
2. **Pembayaran Sukses Berikutnya (Renewal)**: **Rp7.500** per warung.

### Aturan Ketat Perhitungan Komisi:
- **Dihitung Berdasarkan Masing-masing Warung**:
  Status "Pembayaran Pertama" dinilai dari riwayat pembayaran warung spesifik tersebut, **bukan** urutan transaksi keseluruhan mitra.
  *Contoh*: Jika Mitra A memiliki Warung X dan Warung Y:
  - Pembayaran pertama Warung X menghasilkan komisi Rp50.000 untuk Mitra A.
  - Pembayaran pertama Warung Y juga menghasilkan komisi Rp50.000 untuk Mitra A.
  - Pembayaran kedua Warung X menghasilkan komisi Rp7.500 untuk Mitra A.
- **Hanya Dicatat Setelah Verified Settlement**:
  Komisi mitra tidak pernah dicatat saat invoice masih `pending`. Komisi baru dibukukan ke tabel `referral_commission_ledgers` setelah webhook Midtrans terverifikasi sah dan invoice berubah menjadi `paid`.
- **Idempotensi Pencatatan Komisi**:
  Pencatatan komisi menggunakan kunci idempotensi unik berbasis invoice (`idempotency_key = "comm:inv:{invoice_id}"`). Jika terjadi notifikasi webhook duplikat dari Midtrans, UDEV menjamin komisi mitra tidak akan dicatat ganda.
- **Pemisahan Biaya Admin Payment Gateway**:
  Biaya administrasi payment gateway (contoh: MDR QRIS 0.7% atau biaya per transaksi VA) dipisahkan dari pencatatan komisi mitra. Nilai komisi mitra tetap utuh sesuai angka nominal pasti di atas.

---

## 5. Implementasi Alur Referral di Larissama

```
Form Pendaftaran Warung (depan-larissama)
  │
  ├─► [User Input Kode: "MITRA-JAKARTA"]
  │
  ├─► POST /api/v1/subscription/referrals/validate
  │     └─► UDEV API: POST /api/v1/integrations/referrals/validate
  │           └─► Respon: Valid, Diskon Rp75.000 (Harga jadi Rp100.000/bln)
  │
  └─► [User Submit Pendaftaran Warung]
        │
        ├─► Simpan Warung di Database Larissama
        ├─► POST /api/v1/integrations/customers (Sync Profil Warung)
        └─► POST /api/v1/integrations/customers/{id}/referral (Kunci Diskon)
```

Dengan alur ini, saat warung masuk ke halaman langganan untuk pertama kalinya, harga yang tampil pada katalog dan invoice preview sudah otomatis mencerminkan tarif diskon yang terkunci.
