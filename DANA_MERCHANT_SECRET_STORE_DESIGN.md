# Secure Merchant API Key Store DANA (UDEV)

## Status dan batasan tahap 6B.11A

Implementasi ini adalah penyimpanan terenkripsi internal UDEV untuk API key merchant **Sandbox**. Store belum terhubung ke penerbitan key Gateway maupun runtime checkout. Tidak ada endpoint HTTP penerbitan, pembacaan plaintext, atau rotasi pada tahap ini.

Nilai plaintext hanya boleh masuk ke service backend UDEV pada tahap lanjutan yang disetujui. Nilai tersebut tidak boleh masuk ke browser, respons HTTP, audit, cache, session, queue, exception, log, telemetry, atau konfigurasi checkout lama. Merchant `larissama` dan environment production selalu ditolak oleh store.

Konfigurasi lama `DANA_GATEWAY_MERCHANT_API_KEYS` tetap tidak berubah dan masih dipakai checkout yang ada. Migrasi ke store ini baru boleh dilakukan melalui tahap integrasi checkout tersendiri dengan regresi dan rencana rollback yang disetujui.

## Arsitektur yang diterapkan

Tabel `dana_merchant_api_key_secrets` menyimpan ciphertext, nonce, authentication tag, fingerprint HMAC berkunci untuk idempotensi, metadata merchant, versi secret, versi kunci enkripsi, dan status. Plaintext maupun hash API key tanpa kunci tidak disimpan. Tabel `dana_merchant_secret_store_audits` menyimpan event serta metadata aman saja.

Enkripsi memakai AES-256-GCM dengan nonce acak 96-bit dan authentication tag 128-bit. Additional authenticated data mengikat ciphertext ke tujuan store, Sandbox, merchant Gateway, ID API key Gateway, versi kunci enkripsi, dan versi secret. Kunci enkripsi dan kunci fingerprint diturunkan terpisah dengan HKDF-SHA-256.

Kunci tidak pernah diturunkan dari `APP_KEY`. Konfigurasi yang wajib tersedia di secret manager atau environment proses adalah:

```dotenv
DANA_MERCHANT_SECRET_STORE_ACTIVE_KEY_VERSION=v1
DANA_MERCHANT_SECRET_STORE_KEYS={"v1":"base64:<32-byte-key>"}
```

Nilai konfigurasi di atas adalah secret operasional, bukan nilai yang di-commit. Di Windows lokal gunakan user/machine environment variable atau secret manager lokal; jangan masukkan ke `.env` yang dibagikan. Di HOMELAB gunakan secret manager/secret deployment terenkripsi dan inject hanya ke proses UDEV dengan izin minimum.

## Status, idempotensi, dan rotasi

Status yang didukung: `pending`, `active`, `rotation_pending`, `revoked`, dan `failed`.

1. Calon secret dibuat `pending` setelah hasil enkripsi dapat didekripsi ulang di memori.
2. Hanya setelah verifikasi itu sukses, transaksi database menjadikannya `active` dan menulis audit aman.
3. API key Gateway yang identik bersifat idempoten melalui fingerprint HMAC. Fingerprint yang sama mengembalikan record yang ada; fingerprint berbeda dengan identitas Gateway yang sama ditolak.
4. Bila sudah ada secret aktif, secret baru hanya boleh disimpan dengan konfirmasi rotasi eksplisit. Secret lama menjadi `rotation_pending`, sedangkan secret baru menjadi `active` secara atomik.
5. Pencabutan hanya menandai record `revoked`; tahap ini tidak memanggil Gateway.
6. `failed` disediakan untuk kontrak penerbitan tahap berikutnya. Jika Gateway pernah menerbitkan key tetapi verifikasi penyimpanan tidak sukses, key tidak boleh dinyatakan siap atau dicoba ulang secara buta; operasi harus direkonsiliasi dari metadata Gateway dan key dicabut melalui alur eksplisit yang diaudit.

Kontrak 6B.11B yang wajib dipenuhi sebelum endpoint penerbitan dibuat: request idempoten ke Gateway, plaintext hanya diteruskan langsung ke `DanaMerchantApiKeySecretStore`, verifikasi penyimpanan selesai sebelum respons sukses, serta tindakan kompensasi pencabutan untuk hasil Gateway yang tidak berhasil disimpan.

## Backup, recovery, dan rotasi kunci enkripsi

- Backup database harus terenkripsi, aksesnya dibatasi, dan retensinya sesuai kebijakan. Backup tanpa versi kunci enkripsi tidak dapat memulihkan secret; backup tidak boleh berisi file environment atau export plaintext key.
- Simpan setiap versi `DANA_MERCHANT_SECRET_STORE_KEYS` dalam secret manager yang mendukung versioning, audit akses, dan pemulihan. Kunci aktif tidak boleh menggantikan versi lama sebelum seluruh record yang relevan dire-enkripsi dan diverifikasi.
- Rotasi kunci enkripsi: tambahkan versi baru, jadikan aktif untuk penulisan baru, re-enkripsi record secara terkontrol per merchant dalam transaksi, verifikasi pembacaan, lalu hapus akses ke versi lama hanya setelah inventaris dan backup tervalidasi.
- Jika versi kunci hilang atau salah, store gagal tertutup dengan error aman dan checkout belum boleh diintegrasikan. Pemulihan dilakukan dari secret manager/backup kunci yang diotorisasi, bukan dari log, audit, atau browser.
- Akses darurat wajib sementara, least privilege, dan tercatat oleh audit secret manager. Tidak boleh membuat endpoint yang mengembalikan plaintext.

## Kontrol operasi dan deployment

- Gunakan TLS untuk semua koneksi antar-host. Pada Windows lokal, gunakan data dummy/non-rahasia sampai HTTPS lokal tepercaya tersedia untuk credential asli.
- Nonaktifkan `APP_DEBUG` di luar lokal. Logging request body, tracing, crash reporting, dan telemetry harus meredaksi API key, ciphertext, nonce, tag, header `Authorization`, dan semua field credential DANA.
- Jangan taruh payload secret pada job, queue, cache, session, retry persisten, atau audit administratif. Alarm hanya boleh memuat merchant ID, environment, status, serta versi kunci.
- Migration dijalankan hanya pada lingkungan non-produksi setelah backup dan konfigurasi kunci tervalidasi. Tidak ada deployment atau migrasi produksi pada tahap ini.
