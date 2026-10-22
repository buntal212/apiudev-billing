# Sinkronisasi pelanggan Larissama ke UDEV Billing

Dokumen ini adalah kontrak integrasi resmi untuk sinkronisasi pelanggan secara
server-to-server. Alurnya satu arah: backend Larissama menyimpan pelanggan
lokal, mengirim salinan untuk billing ke UDEV, lalu admin memantau hasilnya dari
frontend UDEV. Registrasi pelanggan tidak membuat invoice, pembayaran, atau
subscription. Larissama bertanggung jawab atas data sumber, queue, dan status
sinkronisasi lokal; UDEV bertanggung jawab atas autentikasi, isolasi aplikasi,
validasi, upsert, audit, dan data billing.

Base URL deployment UDEV:

```text
https://apiudev.udumbara.my.id
```

Larissama tidak perlu dan tidak boleh menyimpan kredensial DANA. Semua URL
produksi wajib menggunakan HTTPS.

## Autentikasi

Admin UDEV membuat token pada detail aplikasi. Token lengkap hanya dikirim satu
kali pada respons pembuatan; UDEV hanya menyimpan hash SHA-256. Simpan token di
secret manager Larissama, jangan di browser, log, URL, atau payload audit.

Gunakan header berikut pada setiap request:

```http
Authorization: Bearer udev_...
Accept: application/json
Content-Type: application/json
```

Token terikat pada satu aplikasi dan memiliki scope `customers:read` dan/atau
`customers:write`. Token yang dicabut atau kedaluwarsa ditolak. Batas request
adalah 60 request per menit per token/aplikasi, dengan perlindungan tambahan 120
request per menit per alamat sumber; hormati `Retry-After` untuk respons `429`
dan jangan melakukan retry tanpa batas.

## Sinkronisasi pelanggan

`POST /api/v1/integrations/customers` melakukan upsert berdasarkan pasangan
`application_id` (dari token) dan `external_customer_id`. `application_id` tidak
boleh dikirim oleh klien. `PATCH` hanya memperbarui pelanggan yang sudah ada;
request tersebut tidak membuat record baru.

Contoh request:

```json
{
  "external_customer_id": "larissama-125",
  "name": "Warung Maju Jaya",
  "owner_name": "Budi",
  "email": "budi@example.com",
  "phone": "081234567890",
  "status": "active"
}
```

`GET /api/v1/integrations/customers/{external_customer_id}` membaca pelanggan
yang terikat pada aplikasi token. `PATCH` menerima `name`, `owner_name`,
`email`, `phone`, dan `status` secara parsial. Field invoice, nominal,
subscription, payment, `paid_at`, dan `expires_at` selalu ditolak; billing
dibuat dan dikelola UDEV.

Pelanggan manual UDEV tidak dapat diambil alih oleh integrasi. Jika ID eksternal
tersebut sudah digunakan record manual, API mengembalikan `409` dengan
`error_code=manual_customer_conflict`; tim harus melakukan pemetaan resmi di
UDEV, bukan memaksa overwrite.

## Status dan audit

Respons pelanggan berisi `source`, `first_synced_at`, `last_synced_at`,
`sync_status`, dan `sync_error_code`. Admin dapat melihat metadata token dan
aktivitas sinkronisasi pada detail aplikasi UDEV. Audit hanya menyimpan metadata
aman (operasi, status, ID eksternal, request ID, dan kode error); bearer token,
API key, dan payload rahasia tidak pernah disimpan.

`201` berarti pelanggan baru dibuat, `200` berarti upsert/update berhasil,
`401` berarti token tidak valid/aktif, `403` berarti scope tidak cukup, `404`
berarti pelanggan tidak ditemukan, `409` berarti konflik record manual, dan
`422` berarti payload tidak valid.

## Operasional aman

- Kirim request dari backend Larissama dengan timeout dan idempotency di sisi
  pemanggil; retry hanya untuk timeout/`429`/error 5xx dengan batas percobaan.
- Jangan menganggap redirect checkout atau parameter browser sebagai bukti
  pembayaran. Status invoice dan subscription hanya berasal dari settlement
  UDEV setelah status gateway terverifikasi.
- Rotasi token dengan membuat token baru, menyimpan plaintext sekali tampil,
  melakukan cutover, lalu mencabut token lama.
- Uji integrasi di Sandbox/staging sebelum perubahan produksi. Repository
  `dana-payment` tetap menjadi komponen gateway terpisah.

## Persiapan admin UDEV

1. Buka **Master Aplikasi** di UDEV.
2. Buka detail aplikasi Larissama dan pastikan aplikasi aktif.
3. Buat token, beri nama, pilih `customers:read` dan/atau `customers:write`,
   lalu tentukan expiry bila diperlukan.
4. Salin plaintext token pada dialog. Token lengkap tidak dapat dilihat lagi.
5. Kirim token melalui secret manager ke backend Larissama, bukan melalui
   browser atau chat biasa.
6. Pantau token, jumlah pelanggan, waktu sinkronisasi, dan aktivitas pada
   detail aplikasi. Buat token pengganti sebelum mencabut token lama saat
   rotasi.

## Kontrak endpoint

Semua URL berikut diawali `https://apiudev.udumbara.my.id` dan memakai header
Bearer pada bagian autentikasi.

| Method | Path | Scope | Perilaku |
| --- | --- | --- | --- |
| POST | `/api/v1/integrations/customers` | `customers:write` | Upsert berdasarkan `application_id` dari token + `external_customer_id`; `201` baru, `200` sudah ada. |
| GET | `/api/v1/integrations/customers/{externalId}` | `customers:read` | Hanya membaca pelanggan aplikasi token. |
| PATCH | `/api/v1/integrations/customers/{externalId}` | `customers:write` | Update parsial; ID yang belum ada menghasilkan `404`, tidak membuat record. |

Contoh response sukses (field sesuai resource UDEV):

```json
{
  "data": {
    "id": 125,
    "application_id": 3,
    "external_customer_id": "larissama-125",
    "name": "Warung Maju Jaya",
    "owner_name": "Budi",
    "email": "budi@example.com",
    "phone_number": "081234567890",
    "is_active": true,
    "source": "integration",
    "first_synced_at": "2026-10-22T08:00:00.000000Z",
    "last_synced_at": "2026-10-22T08:00:00.000000Z",
    "sync_status": "synced",
    "sync_error_code": null
  },
  "sync": { "status": "accepted" }
}
```

`application_id` pada response adalah identitas UDEV; jangan kirim nilainya
untuk memilih tenant. Field billing seperti `subscription_id`, `invoice`,
`amount`, `paid_at`, dan `expires_at` ditolak dengan `422`.

## Contoh Laravel untuk backend Larissama

Contoh ini memakai HTTP Client Laravel. Sesuaikan nama model/status lokal tanpa
menganggap struktur database Larissama tertentu.

```php
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

function udevClient(string $token): PendingRequest
{
    return Http::baseUrl('https://apiudev.udumbara.my.id')
        ->acceptJson()
        ->withToken($token)
        ->connectTimeout(3)
        ->timeout(10);
}

$response = udevClient(config('services.udev.token'))
    ->post('/api/v1/integrations/customers', [
        'external_customer_id' => (string) $customer->external_id,
        'name' => $customer->name,
        'owner_name' => $customer->owner_name,
        'email' => $customer->email,
        'phone' => $customer->phone,
        'status' => $customer->is_active ? 'active' : 'inactive',
    ]);
```

Dispatch queue job setelah transaksi lokal commit (misalnya memakai
`->afterCommit()`), simpan `last_attempt_at`, `last_http_status`, dan kode
error aman di tabel sinkronisasi lokal, lalu batasi retry. Contoh kebijakan:

```php
public $tries = 5;
public function backoff(): array { return [5, 30, 120, 600]; }

public function handle(): void
{
    $response = udevClient(config('services.udev.token'))
        ->post('/api/v1/integrations/customers', $this->payload);

    if ($response->successful()) {
        // Tandai synced dan simpan ID UDEV; jangan mengubah billing lokal.
        return;
    }

    if ($response->status() === 429) {
        $delay = max(1, (int) $response->header('Retry-After', 60));
        $this->release(min($delay, 3600));
        return;
    }

    if ($response->status() === 401 || $response->status() === 403) {
        // Hentikan retry otomatis dan beri notifikasi operator.
        throw new RuntimeException('Token UDEV tidak dapat digunakan.');
    }

    if ($response->status() === 409) {
        // Konflik record manual; perlu mapping resmi di UDEV.
        throw new RuntimeException('Konflik external customer ID.');
    }

    if ($response->serverError() || $response->status() === 408) {
        throw new RuntimeException('UDEV sementara tidak tersedia.');
    }

    // 404/422 disimpan sebagai validation/not_found tanpa retry tanpa batas.
    throw new RuntimeException('Payload sinkronisasi ditolak UDEV.');
}
```

Jangan menyimpan bearer token ke model pelanggan, log request, browser,
`localStorage`, atau URL. Untuk query status, gunakan GET endpoint dan simpan
hanya metadata response yang diperlukan.

## Error dan troubleshooting

- **401**: token salah, belum dikirim, dicabut, atau kedaluwarsa. Buat token
  baru; jangan mengulang request tanpa memperbaiki secret.
- **403**: scope tidak mencukupi atau aplikasi tidak aktif. Periksa scope dan
  status aplikasi di UDEV.
- **404**: ID eksternal belum ada untuk GET/PATCH. Gunakan POST untuk upsert.
- **409**: ID sudah dimiliki pelanggan manual. Hentikan retry dan minta mapping
  admin; jangan overwrite otomatis.
- **422**: validasi field gagal atau payload memuat field billing terlarang.
- **429**: tunggu nilai `Retry-After`, gunakan backoff terbatas, dan cek apakah
  ada worker atau deployment yang mengirim duplikat.
- **500/502/503/timeout**: simpan status `pending`, retry terbatas dengan
  exponential backoff, dan jangan menganggap pelanggan sudah tersinkron sebelum
  menerima response UDEV.
- **Tidak muncul di UDEV**: cek response job Larissama, external ID, token
  aplikasi, filter sumber pada Master Pelanggan, dan riwayat audit.
- **Duplikat**: pastikan `external_customer_id` stabil. POST ulang pada ID yang
  sama aman karena unique key aplikasi + ID eksternal.

## Checklist UAT Larissama

- [ ] Admin membuat token dengan scope yang sesuai dan menyimpannya di server.
- [ ] Request POST pelanggan baru mendapat `201` dan muncul sebagai sumber
      `integration` di Master Pelanggan.
- [ ] POST ulang ID yang sama mendapat `200` tanpa record kedua.
- [ ] PATCH memperbarui data dan GET membaca hasil terbaru.
- [ ] Token aplikasi lain tidak dapat membaca ID Larissama.
- [ ] Token dicabut/kedaluwarsa menghasilkan `401`; scope salah menghasilkan
      `403`.
- [ ] Gangguan UDEV tidak menghapus data pelanggan lokal dan queue dapat pulih.
- [ ] `429` menghormati `Retry-After` dan jumlah retry terbatas.
- [ ] Tidak ada invoice, settlement, pembayaran, atau subscription yang berubah
      hanya karena sinkronisasi pelanggan.

## Catatan billing

Pelanggan baru terdaftar tidak sama dengan langganan aktif. Invoice dibuat
melalui alur billing UDEV, checkout DANA tetap Sandbox-only, dan status lunas
hanya berasal dari gateway yang telah diverifikasi serta settlement. Redirect
browser tidak pernah menjadi bukti pembayaran.
