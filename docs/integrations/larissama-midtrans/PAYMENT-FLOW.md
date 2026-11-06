# DIAGRAM ALUR PEMBAYARAN & SINKRONISASI
## UDEV CENTRALIZED BILLING ↔ LARISSAMA (MIDTRANS)

---

## 1. Diagram A: Penerbitan Invoice (Create Invoice Flow)

Menjelaskan bagaimana pelanggan di frontend Larissama memilih paket, meminta preview harga/diskon, dan menerbitkan invoice penagihan resmi berstatus `pending` di UDEV.

```mermaid
sequenceDiagram
    autonumber
    actor Pelanggan as Pemilik Warung
    participant Front as Larissama Frontend (Quasar)
    participant Back as Larissama Backend (Laravel)
    participant UdevAPI as UDEV Billing API
    participant DB_UDEV as Database UDEV MySQL

    Pelanggan->>Front: Buka Halaman Langganan
    Front->>Back: GET /api/v1/subscription/plans
    Back->>UdevAPI: GET /api/v1/integrations/subscription-plans
    UdevAPI-->>Back: 200 OK (Katalog Paket)
    Back-->>Front: Render Kartu Paket

    Pelanggan->>Front: Pilih Paket & Geser Slider Durasi (Misal: 3 Periode)
    Front->>Back: POST /api/v1/subscription/preview {plan_code, period_count: 3}
    Back->>UdevAPI: POST /api/v1/integrations/customers/{id}/invoice-preview
    Note over UdevAPI: Hitung 175.000 × 3 = 525.000<br/>Cek referral terkunci (Diskon 75.000 × 3 = 225.000)<br/>Final Price = 300.000 (Durasi 90 hari)
    UdevAPI-->>Back: 200 OK (Rincian Biaya & Diskon)
    Back-->>Front: Tampilkan Ringkasan Tagihan

    Pelanggan->>Front: Klik "Lanjut ke Pembayaran"
    Front->>Back: POST /api/v1/subscription/order (Idempotency-Key)
    Back->>UdevAPI: POST /api/v1/integrations/customers/{id}/invoices
    UdevAPI->>DB_UDEV: Simpan Record Invoice (status: pending, due_at: +24 jam)
    DB_UDEV-->>UdevAPI: Invoice Created
    UdevAPI-->>Back: 201 Created (invoice_number: INV-202611-0001)
```

---

## 2. Diagram B: Inisiasi Checkout Midtrans (Checkout Flow)

Menjelaskan proses UDEV memanggil microservice `mitrans-payment` dan Midtrans Core API hingga mengembalikan instruksi bayar (QRIS / Nomor VA) ke pelanggan.

```mermaid
sequenceDiagram
    autonumber
    participant Back as Larissama Backend
    participant UdevAPI as UDEV Billing API
    participant Checkout as CheckoutService (UDEV)
    participant MitransSVC as mitrans-payment Microservice
    participant MidtransCore as Midtrans Core API
    participant Front as Larissama Frontend

    Back->>UdevAPI: POST /api/v1/integrations/invoices/INV-202611-0001/checkout<br/>{provider: "midtrans", payment_method: "va_bca"}
    UdevAPI->>Checkout: startCheckout(invoice, key, method, provider)
    Note over Checkout: Ambil kredensial merchant 'larissama'<br/>Dekripsi Server Key AES-256-GCM<br/>Bentuk Payment Attempt (INV-202611-0001-A1)

    alt Via Internal Microservice (mitrans-payment)
        Checkout->>MitransSVC: POST /api/v1/internal/merchants/larissama/charge
        MitransSVC->>MidtransCore: POST /v2/charge (Server Key Auth)
        MidtransCore-->>MitransSVC: 201 Created (va_numbers: [{bank: bca, va_number: 100889977112233}])
        MitransSVC-->>Checkout: 201 OK + Instructions
    else Fallback Direct Core API
        Checkout->>MidtransCore: Direct POST /v2/charge (Basic Auth ServerKey)
        MidtransCore-->>Checkout: 201 Created
    end

    Note over Checkout: Simpan payment_code, instruction, expiry<br/>Update PaymentAttempt status: ready
    Checkout-->>UdevAPI: Attempt Ready
    UdevAPI-->>Back: 200 OK (data.payment.payment_code: "100889977112233")
    Back-->>Front: Render Dialog Nomor VA + Tombol Copy + Panduan Transfer
```

---

## 3. Diagram C: Penyelesaian Pembayaran & Settlement (Settlement Flow)

Menjelaskan alur webhook notifikasi Midtrans saat pelanggan berhasil membayar, verifikasi signature, settlement idempoten, dan penambahan masa aktif.

```mermaid
sequenceDiagram
    autonumber
    actor Pelanggan as Pelanggan
    participant BankMidtrans as Midtrans Gateway Engine
    participant UdevWebhook as MidtransWebhookController (UDEV)
    participant SettlementSVC as InvoiceSettlementService
    participant SubSVC as SubscriptionService
    participant ReferralSVC as ReferralCommissionService
    participant DB_UDEV as Database UDEV MySQL
    participant Back as Larissama Backend
    participant Front as Larissama Frontend

    Pelanggan->>BankMidtrans: Transfer ke VA BCA / Scan QRIS (Sukses)
    BankMidtrans->>UdevWebhook: POST /api/v1/payment-gateways/midtrans/webhook<br/>{order_id, status_code: "200", gross_amount, signature_key, transaction_status: "settlement"}

    Note over UdevWebhook: Verifikasi Signature SHA512:<br/>SHA512(order_id + 200 + gross_amount + ServerKey)<br/>Gunakan hash_equals() (Aman dari Timing Attack)

    UdevWebhook->>SettlementSVC: settle(paymentAttempt, verification)
    Note over SettlementSVC: DB::transaction & lockForUpdate():<br/>1. Kunci baris Invoice & PaymentAttempt<br/>2. Cek tabel payment_allocations (Idempotensi)<br/>3. Buat PaymentAllocation tunggal<br/>4. Ubah status Invoice = 'paid', paid_at = now()

    SettlementSVC->>ReferralSVC: recordVerifiedAllocation(allocation)
    Note over ReferralSVC: Catat komisi mitra Rp50.000 / Rp7.500<br/>Key unik: comm:inv:{id}

    SettlementSVC->>SubSVC: renewFromVerifiedPayment(subscription, durationDays)
    Note over SubSVC: Cek masa aktif berjalan:<br/>Jika aktif: new_expires_at = previous_expires_at + 30 hari<br/>Catat SubscriptionPeriodEvent idempoten

    SettlementSVC->>DB_UDEV: Commit Transaction
    UdevWebhook-->>BankMidtrans: 200 OK ({"status": "OK", "message": "Lunas"})

    Note over Front,Back: Frontend Polling / Status Query
    Front->>Back: GET /api/v1/subscription/status/INV-202611-0001
    Back->>UdevWebhook: GET /api/v1/integrations/invoices/INV-202611-0001
    UdevWebhook-->>Back: 200 OK (status: "paid", subscription: {expires_at: "2026-12-06"})
    Back->>Back: Update Warung Lokal: tanggal_berakhir = 2026-12-06, aktif = true
    Back-->>Front: 200 OK (Status: Lunas & Masa Aktif Terbaru)
    Front->>Pelanggan: Tampilkan Tanda Centang Hijau "Pembayaran Berhasil!"
```

---

## 4. Diagram D: Pembayaran Gagal atau Kedaluwarsa (Failure & Expiry Flow)

Menjelaskan penanganan kondisi saat pelanggan tidak membayar hingga batas waktu habis atau transaksi ditolak.

```mermaid
sequenceDiagram
    autonumber
    participant MidtransPG as Midtrans Gateway
    participant UdevWebhook as UDEV Webhook Receiver
    participant AttemptSVC as PaymentAttemptService
    participant DB_UDEV as Database UDEV MySQL
    participant Front as Larissama Frontend
    participant Back as Larissama Backend

    MidtransPG->>UdevWebhook: Webhook {transaction_status: "expire" / "cancel" / "deny"}
    Note over UdevWebhook: Verifikasi SHA512 Signature
    UdevWebhook->>AttemptSVC: transitionStatus(attempt, StatusExpired / StatusCancelled)
    AttemptSVC->>DB_UDEV: Update PaymentAttempt status = 'expired'<br/>Invoice status TETAP 'pending' jika due_at masih valid
    UdevWebhook-->>MidtransPG: 200 OK

    Front->>Back: Polling status pembayaran
    Back->>UdevWebhook: GET /api/v1/integrations/invoices/INV-202611-0001
    UdevWebhook-->>Back: 200 OK (payment.status: "expired")
    Back-->>Front: Tampilkan Status: "Batas Waktu Pembayaran Habis"
    
    Note over Front: Tampilkan Tombol "Ulangi Pembayaran (Retry)"
    Front->>Back: POST /api/v1/subscription/invoices/INV-202611-0001/retry<br/>{payment_method: "qris"}
    Back->>UdevWebhook: POST /api/v1/integrations/invoices/INV-202611-0001/checkout (Attempt-2)
    Note over UdevWebhook: Menerbitkan QRIS baru pada invoice yang sama
    UdevWebhook-->>Back: 200 OK (QRIS Baru)
    Back-->>Front: Tampilkan QRIS Baru
```

---

## 5. Diagram E: Sinkronisasi Akses & Masa Aktif Warung (Access Check Flow)

Menjelaskan bagaimana Larissama memastikan warung yang beroperasi memiliki paket aktif dari UDEV.

```mermaid
sequenceDiagram
    autonumber
    actor Owner as Pemilik Warung
    participant App as Larissama App
    participant Back as Larissama Backend
    participant UdevAPI as UDEV Centralized Billing

    Owner->>App: Login / Buka Dashboard Warung
    App->>Back: Cek Sesi & Akses Warung
    Back->>Back: Periksa tanggal_berakhir lokal
    
    alt Jika Mendekati Habis atau Berkala
        Back->>UdevAPI: GET /api/v1/integrations/customers/{warung_id}/subscription
        UdevAPI-->>Back: 200 OK {status: "active", expires_at: "2026-12-06"}
        Back->>Back: Sinkronkan warungs.tanggal_berakhir = expires_at
    end

    alt Jika Masa Aktif Masih Berlaku
        Back-->>App: Izinkan Akses Penuh (Kasir, Transaksi, Laporan)
    else Jika Telah Kedaluwarsa (Dalam Masa Tenggang 30 Hari)
        Back-->>App: Mode Terbatas (Hanya Halaman Pembayaran & Laporan Riwayat)
        App->>Owner: Tampilkan Banner Oranye "Masa aktif habis, segera perpanjang langganan"
    else Jika Melewati Masa Tenggang
        Back-->>App: Blokir Akses Warung (Wajib Hubungi Admin)
    end
```
