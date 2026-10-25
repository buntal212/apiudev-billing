# Integrasi Referral Larissama

Base URL: `https://billing.udev.example/api/v1/integrations`.

Simpan Global API Token hanya di backend Larissama. Jangan pernah kirim token ke browser, aplikasi mobile, log, ticket, atau pesan chat.

## Autentikasi

Admin UDEV membuat atau merotasi token pada detail aplikasi. Token utuh hanya ditampilkan sekali. Kirim pada setiap request:

```http
Authorization: Bearer udev_global_<token-placeholder>
Accept: application/json
Idempotency-Key: <uuid-baru-untuk-operasi-tulis>
```

Token terikat ke aplikasi dari database UDEV; Larissama tidak mengirim `application_id`. Saat rotasi, token lama tetap berlaku maksimal 15 menit.

## Validasi referral

`POST /referrals/validate`

```json
{"referral_code":"REF-A1B2C3"}
```

```json
{"data":{"code":"REF-A1B2C3","type":"umum","base_price":"175000.00","discount_amount":"50000.00","customer_price":"125000.00"},"request_id":"..."}
```

Jenis `khusus` memberikan diskon `75000.00` dan harga `100000.00`. Harga dari respons UDEV adalah satu-satunya harga yang boleh dipakai Larissama.

## Registrasi pelanggan dan penguncian

`POST /customers`

```json
{"external_customer_id":"SALON-001","name":"Nama Pelanggan","status":"active","referral_code":"REF-A1B2C3"}
```

UDEV melakukan upsert idempoten per aplikasi dan `external_customer_id`. Referral hanya dikunci pada pelanggan baru. Pengiriman ulang dengan referral sama aman; referral berbeda pada pelanggan yang telah terkunci menghasilkan `409`.

Untuk pelanggan lama tanpa referral, gunakan tindakan eksplisit:

`POST /customers/{externalId}/referral`

```json
{"referral_code":"REF-A1B2C3"}
```

`GET /customers/{externalId}` memeriksa pelanggan, sedangkan `GET /customers/{externalId}/subscription` mengembalikan status langganan dan snapshot referral tanpa data pribadi mitra.

## Contoh Laravel HTTP Client

```php
$response = Http::acceptJson()
    ->withToken(config('services.udev.global_token'))
    ->withHeader('Idempotency-Key', (string) Str::uuid())
    ->post(config('services.udev.url').'/api/v1/integrations/customers', [
        'external_customer_id' => $customer->uuid,
        'name' => $customer->name,
        'status' => 'active',
        'referral_code' => $request->validated('referral_code'),
    ]);
```

Gunakan timeout, retry terbatas hanya untuk kegagalan jaringan/`503`, dan idempotency key yang sama saat mengulang operasi tulis yang sama.

## Error dan retry

| Status | Arti | Tindakan |
| --- | --- | --- |
| 401 | Token hilang, tidak valid, kedaluwarsa, atau dicabut | Perbaiki konfigurasi backend token. |
| 403 | Token bukan Global API Token atau aplikasi tidak aktif | Hubungi Admin UDEV. |
| 404 | Pelanggan tidak ada pada aplikasi token | Jangan mencoba aplikasi lain. |
| 409 | Konflik ID pelanggan atau referral sudah terkunci | Ambil data pelanggan dan jangan mengganti harga. |
| 422 | Payload/referral tidak valid | Perbaiki input, jangan retry otomatis. |
| 429 | Rate limit | Tunggu `Retry-After`. |
| 503 | Kode/token tidak dapat diproses sementara | Retry terbatas dengan idempotency key sama. |

Setiap respons integrasi menyertakan `request_id` untuk investigasi aman oleh tim UDEV.

## Paket dan pembayaran DANA Sandbox

Semua endpoint di bawah memakai Global API Token yang sama. UDEV menentukan aplikasi dari token, sehingga Larissama tidak boleh mengirim `application_id`, harga, diskon, currency, atau `subscription_id`.

### Daftar paket

`GET /subscription-plans?per_page=15`

Endpoint hanya mengembalikan paket aktif untuk aplikasi token. Gunakan `code` paket untuk endpoint berikutnya.

```json
{"data":[{"code":"LARIS-30","name":"Larissama 30 Hari","price":"175000.00","currency":"IDR","duration_days":30,"is_active":true}]}
```

### Preview harga

`POST /customers/{externalId}/invoice-preview`

```json
{"subscription_plan_code":"LARIS-30"}
```

```json
{"data":{"customer_external_id":"SALON-001","plan":{"code":"LARIS-30","duration_days":30},"pricing":{"normal_price":"175000.00","referral_code":"REF-A1B2C3","referral_type":"umum","discount_amount":"50000.00","final_price":"125000.00","currency":"IDR"}},"request_id":"..."}
```

Harga preview dihitung UDEV dari snapshot referral yang sudah terkunci. Larissama tidak boleh menghitung ulang atau mengganti nilainya.

### Buat invoice

`POST /customers/{externalId}/invoices`

```http
Idempotency-Key: invoice-salon-001-20261026-01
```

```json
{"subscription_plan_code":"LARIS-30","due_at":"2026-10-27T12:00:00+07:00"}
```

Respons berisi nomor invoice, snapshot paket dan harga, status pembayaran, serta status masa aktif. Gunakan `Idempotency-Key` yang sama untuk mengulang request yang sama setelah gangguan jaringan. Kunci yang sudah dipakai pelanggan lain akan ditolak.

Paket tujuan boleh berbeda dari paket subscription aktif. Perubahan paket dan penambahan durasi hanya terjadi setelah pembayaran DANA Sandbox terverifikasi; bila periode masih aktif, durasi baru ditambahkan dari tanggal berakhir terakhir. Pembayaran pending, gagal, kedaluwarsa, atau dibatalkan tidak mengubah paket maupun masa aktif.

### Checkout DANA Sandbox

`POST /invoices/{invoiceNumber}/checkout`

```http
Idempotency-Key: checkout-inv-20261026-01
```

Tidak ada body yang diperlukan. Jika checkout telah siap, respons `200` menyertakan:

```json
{"data":{"invoice_number":"INV-...","payment":{"status":"pending","provider":"dana","checkout_status":"ready","checkout_url":"https://...","checkout_expires_at":"2026-10-27T12:00:00Z","transaction_id":"INV-...-A1"}},"request_id":"..."}
```

Status `202` berarti checkout masih diproses. Jangan membuat payment baru; panggil endpoint yang sama dengan idempotency key yang sama atau periksa invoice kemudian. Checkout hanya memakai mapping merchant DANA **Sandbox** aktif milik aplikasi token.

### Status invoice, pembayaran, masa aktif, dan tracking

- `GET /invoices/{invoiceNumber}` mengembalikan snapshot invoice, status pembayaran DANA, URL checkout yang masih tersedia, settlement, serta masa aktif.
- `GET /customers/{externalId}/subscription` mengembalikan status langganan dan snapshot referral pelanggan.
- `GET /customers/{externalId}/transactions?per_page=15` mengembalikan riwayat invoice terurut terbaru, ID transaksi, status pembayaran, settlement, serta event masa aktif/perubahan paket yang terkait.

Semua endpoint di atas diisolasi oleh aplikasi dari token. Nomor invoice atau ID pelanggan aplikasi lain selalu diperlakukan sebagai `404`.
