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

## Paket dan pembayaran

Gunakan `GET /subscription-plans` untuk mengambil paket aktif aplikasi token. Saat membuat invoice, UDEV memvalidasi bahwa pelanggan, paket, dan langganan berasal dari aplikasi yang sama. Harga invoice adalah snapshot UDEV: harga paket normal bila tanpa referral, atau harga referral yang telah terkunci.

Paket tujuan boleh berbeda dari paket subscription aktif. Perubahan paket dan penambahan durasi hanya terjadi setelah pembayaran DANA Sandbox terverifikasi; bila periode masih aktif, durasi baru ditambahkan dari tanggal berakhir terakhir. Pembayaran pending, gagal, kedaluwarsa, atau dibatalkan tidak mengubah paket maupun masa aktif.
