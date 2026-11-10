# Panduan Integrasi TransCXP → TransSurvey

Dokumen ini untuk tim TransCXP. Ada dua cara mengarahkan user dari TransCXP ke
TransSurvey:

| Jalur | Perlu JWT? | Perlu key pair? | Disarankan |
| --- | --- | --- | --- |
| **A. Launch session API** | Tidak | Tidak | ✅ Ya |
| B. JWT (HS256/ES256/RS256) | Ya | HS256: shared secret; ES256/RS256: key pair | Kalau memang mau menandatangani sendiri |

Kalau tidak ada kebutuhan khusus, **pakai Jalur A**. TransCXP cukup memanggil
satu endpoint server-to-server, lalu melempar user ke URL yang dibalas. Tidak
ada JWT, tidak ada key pair, dan tidak ada data customer di URL.

---

## 1. Ringkasan alur (Jalur A — disarankan)

```text
User klik tombol CSAT di TransCXP
        │
        ▼
Server TransCXP memanggil POST /api/cxp/launch-sessions
(header API key, body: user + project [+ tiket])
        │
        ▼
TransSurvey membalas { code, url, expires_in }
        │
        ▼
Browser diarahkan ke url (hanya berisi kode acak, sekali pakai)
        │
        ▼
TransSurvey menukar kode, membuat/mencocokkan akun,
lalu login otomatis sebagai user operation.
```

Pemanggilan API dilakukan di **backend TransCXP**, bukan di browser, karena API
key tidak boleh sampai ke client.

---

## 2. Jalur A: Launch session API

### 2.1 Yang perlu disiapkan

1. **URL TransSurvey** (staging/production) dari tim TransSurvey.
2. **API key** (`CXP_LAUNCH_API_KEY`), dibuat oleh tim TransSurvey dan dikirim
   lewat kanal aman (password manager / jalur terenkripsi).
3. **Kesepakatan `project.code`** yang stabil dan tidak berubah-ubah.

API key **hanya** dipakai di server TransCXP. Jangan taruh di frontend, repo,
screenshot, atau chat biasa.

### 2.2 Request

```http
POST https://<transsurvey-host>/api/cxp/launch-sessions
Authorization: Bearer <CXP_LAUNCH_API_KEY>
Content-Type: application/json
Accept: application/json
```

Header `Authorization: Bearer <key>` boleh diganti `X-CXP-Key: <key>`.

Body login saja:

```json
{
  "user":    { "id": "5", "name": "Agent A", "email": "agent@email.com" },
  "project": { "id": 23, "code": "23", "name": "Panasonic" }
}
```

Body dengan satu tiket (opsional):

```json
{
  "user":    { "id": "5", "name": "Agent A", "email": "agent@email.com" },
  "project": { "id": 23, "code": "23", "name": "Panasonic" },
  "ticket": {
    "id": "101",
    "status": "open",
    "channel": "whatsapp",
    "customer": {
      "id": "10",
      "name": "Customer A",
      "email": "customerA@email.com",
      "wa": "081234567890"
    }
  }
}
```

### 2.3 Balasan sukses (`201`)

```json
{
  "code": "84dcf11904405b4036b5dea7413377e1f6beea69ca39fd729fed86d00406e19e",
  "url": "https://<transsurvey-host>/cxp/launch?code=84dcf11904405b4036b5dea7413377e1f6beea69ca39fd729fed86d00406e19e",
  "expires_in": 120
}
```

Lalu arahkan browser user ke `url`:

```php
header('Location: '.$response['url'], true, 302);
exit;
```

Setelah `url` dibuka, TransSurvey login otomatis lalu mengarahkan browser:

| Payload | Tujuan |
| --- | --- |
| `user` + `project` saja | Dashboard |
| `user` + `project` + `ticket` + `customer.email` | Halaman Kirim CSAT (`/csat/send`) |

Di halaman Kirim CSAT, operation melihat data tiket/customer, memilih template,
lalu mengirim CSAT lewat email. `customer.wa` yang dikirim akan tampil di
ringkasan dan disimpan sebagai data client untuk kebutuhan broadcast.

Aturan kode:

- 64 karakter hex, **sekali pakai**.
- Berlaku `CXP_LAUNCH_CODE_TTL` detik (default **120**).
- Kalau kedaluwarsa atau dipakai dua kali, `/cxp/launch?code=...` membalas `401`.
- Tidak ada email customer di URL; yang beredar hanya kode acak.
- Kode dibuat baru untuk **setiap** klik tombol CSAT.

### 2.4 Kalau server TransCXP memanggil lewat host internal

`url` pada balasan mengikuti host yang dipakai saat memanggil. Pastikan
TransCXP memanggil lewat **URL publik TransSurvey**, supaya `url` yang dibalas
juga publik dan bisa dibuka browser user.

### 2.5 Error Jalur A

| Status | Arti |
| --- | --- |
| `401` | API key salah atau tidak dikirim. |
| `422` | Payload tidak valid (lihat aturan data di bagian 4). |
| `429` | Rate limit tercapai. |
| `500` | `CXP_LAUNCH_API_KEY` belum diisi di TransSurvey. |

Contoh balasan `422`:

```json
{
  "message": "Data pada token SSO tidak lengkap.",
  "reason": "invalid_claim"
}
```

### 2.6 Rate limit

- 60 permintaan/menit per IP.
- 300 permintaan/menit secara global.

Kalau butuh lebih, koordinasikan dengan tim TransSurvey.

---

## 3. Jalur B: JWT (alternatif)

Pakai jalur ini hanya kalau TransCXP memang ingin membuat dan menandatangani
token sendiri. Algoritma yang didukung: **HS256**, **ES256**, **RS256**.

- **HS256** memakai shared secret (paling sederhana).
- **ES256/RS256** memakai key pair: TransCXP menandatangani dengan private key,
  TransSurvey memverifikasi dengan public key. Private key **tidak pernah**
  dikirim ke TransSurvey.

### 3.1 Alur

```text
Server TransCXP membuat JWT berisi user + project
        │
        ▼
Browser diarahkan ke:
https://<transsurvey-host>/cxp/launch?sso=<JWT>
        │
        ▼
TransSurvey verifikasi tanda tangan, membuat/mencocokkan akun,
lalu login otomatis.
```

Token dibuat di backend, bukan di browser.

### 3.2 Payload JWT — login saja

```json
{
  "iss": "transcxp",
  "aud": "transsurvey",
  "iat": 1793970000,
  "exp": 1793970300,
  "jti": "8f2c1a7e9b3d4c5a",
  "user": {
    "id": "5",
    "name": "Agent A",
    "email": "agent@email.com"
  },
  "project": {
    "id": 23,
    "code": "23",
    "name": "Panasonic"
  }
}
```

### 3.3 Payload JWT — dengan satu tiket (opsional)

```json
{
  "iss": "transcxp",
  "aud": "transsurvey",
  "iat": 1793970000,
  "exp": 1793970300,
  "jti": "8f2c1a7e9b3d4c5a",
  "user": {
    "id": "5",
    "name": "Agent A",
    "email": "agent@email.com"
  },
  "project": {
    "id": 23,
    "code": "23",
    "name": "Panasonic"
  },
  "ticket": {
    "id": "101",
    "status": "open",
    "channel": "whatsapp",
    "customer": {
      "id": "10",
      "name": "Customer A",
      "email": "customerA@email.com",
      "wa": "081234567890"
    }
  }
}
```

Penting:

- `customer` diletakkan **di dalam** `ticket`, supaya pasangan tiket–customer jelas.
- Jangan mengirim array berisi lebih dari satu tiket. Token SSO adalah konteks
  login untuk satu tiket. Array berisi tepat satu tiket masih diterima, tetapi
  bentuk objek lebih disarankan.
- Kalau butuh daftar tiket, itu lewat endpoint terpisah (Fase 4).

### 3.4 Aturan klaim JWT

| Klaim | Wajib | Keterangan |
| --- | --- | --- |
| `iss` | Tidak* | Kalau dikirim, harus `transcxp`. |
| `aud` | Tidak* | Kalau dikirim, harus memuat `transsurvey`. |
| `iat` | Ya | Unix timestamp detik. |
| `exp` | Ya | Unix timestamp detik; harus lebih besar dari `iat`. |
| `jti` | Ya | Unik untuk setiap klik, maksimal 255 karakter. |
| `user.id` | Ya | ID user di TransCXP. |
| `user.email` | Ya | Email valid. |
| `user.name` | Tidak | Kalau kosong, dipakai email. |
| `project.code` | Ya | Kode project; menjadi kode workspace di TransSurvey. |
| `project.name` | Tidak | Kalau kosong, dipakai `project.code`. |
| `ticket.id` | Tidak | Opsional. Kalau dikirim, `customer.email` wajib ikut. |
| `ticket.status` | Tidak | Sebaiknya string, mis. `open`, `resolved`. |
| `ticket.channel` | Tidak | Sebaiknya string, mis. `whatsapp`, `email`. |
| `customer.email` | Tidak | Wajib bila `ticket` dikirim. Email valid. |
| `customer.wa` | Tidak | Nomor WhatsApp customer; dinormalkan ke format `62...` untuk broadcast. |
| `customer.name` | Tidak | Kalau kosong, dipakai email. |

\* Wajib bila `CXP_SSO_REQUIRE_ISS_AUD=true`. Default TransSurvey: `false`,
jadi `iss`/`aud` boleh tidak dikirim. Tetap disarankan mengirim keduanya.

Batasan:

- Umur token maksimal **5 menit** (`exp - iat`). Jangan membuat token saat
  halaman dimuat jauh sebelum tombol diklik.
- Token maksimal 8192 karakter.
- `jti` harus baru setiap kali tombol diklik. Token yang sama tidak bisa dipakai
  dua kali.
- `project.code` dan `user.email` dinormalisasi menjadi huruf kecil oleh
  TransSurvey.

### 3.5 Contoh kode PHP (HS256)

```php
<?php

declare(strict_types=1);

function base64UrlEncode(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function makeCxpSsoToken(array $config): string
{
    $now = time();

    $payload = [
        'iss' => 'transcxp',
        'aud' => 'transsurvey',
        'iat' => $now,
        'exp' => $now + 300,
        'jti' => bin2hex(random_bytes(16)),
        'user' => [
            'id' => (string) $config['userId'],
            'name' => (string) $config['userName'],
            'email' => (string) $config['userEmail'],
        ],
        'project' => [
            'id' => $config['projectId'],
            'code' => (string) $config['projectCode'],
            'name' => (string) $config['projectName'],
        ],
    ];

    // Opsional: hanya kalau membuka satu tiket tertentu.
    if (! empty($config['ticket'])) {
        $ticket = $config['ticket'];

        if (empty($ticket['customer']['email'])) {
            throw new InvalidArgumentException('customer.email wajib diisi bila ticket dikirim.');
        }

        $payload['ticket'] = [
            'id' => (string) $ticket['id'],
            'status' => (string) ($ticket['status'] ?? ''),
            'channel' => (string) ($ticket['channel'] ?? ''),
            'customer' => [
                'id' => (string) ($ticket['customer']['id'] ?? ''),
                'name' => (string) ($ticket['customer']['name'] ?? $ticket['customer']['email']),
                'email' => (string) $ticket['customer']['email'],
                'wa' => (string) ($ticket['customer']['wa'] ?? ''),
            ],
        ];
    }

    $header = ['alg' => 'HS256', 'typ' => 'JWT'];

    $unsigned = base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
        .'.'.base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    $signature = hash_hmac('sha256', $unsigned, $config['secret'], true);

    return $unsigned.'.'.base64UrlEncode($signature);
}

// Contoh pemakaian saat user menekan tombol CSAT.
$token = makeCxpSsoToken([
    'secret' => getenv('CXP_SSO_SECRET'),
    'userId' => 5,
    'userName' => 'Agent A',
    'userEmail' => 'agent@email.com',
    'projectId' => 23,
    'projectCode' => '23',
    'projectName' => 'Panasonic',
]);

$url = 'https://<transsurvey-host>/cxp/launch?sso='.urlencode($token);

header('Location: '.$url, true, 302);
exit;
```

Catatan:

- Token harus dibuat di server, bukan JavaScript di browser.
- `urlencode()` wajib dipakai karena token berisi karakter `-`, `_`, dan `.`.
- Kalau stack kalian bukan PHP, logikanya sama: base64url(header) + "." +
  base64url(payload), lalu HMAC-SHA256 dengan secret, lalu base64url(signature).

### 3.6 Error Jalur B

| Status | Arti |
| --- | --- |
| `401` | Token hilang, tidak valid, kedaluwarsa, tanda tangan salah, algoritma salah, `jti` sudah dipakai, atau berisi lebih dari satu tiket. |
| `403` | Akun SSO ada tetapi bukan role `operation`. |
| `409` | Email bentrok dengan akun lain, atau `project.code` memakai workspace legacy. |
| `429` | Rate limit `/cxp/launch` tercapai. |
| `500` | Konfigurasi SSO di TransSurvey belum lengkap. |

Pesan error tidak menampilkan token. Detail alasan dicatat di log server
TransSurvey dengan penanda `CXP SSO rejected`.

---

## 4. Aturan data (berlaku untuk kedua jalur)

| Field | Wajib | Keterangan |
| --- | --- | --- |
| `user.id` | Ya | Maksimal 191 karakter. |
| `user.email` | Ya | Email valid, maksimal 255 karakter. |
| `user.name` | Tidak | Maksimal 255 karakter; kalau kosong dipakai email. |
| `project.code` | Ya | Maksimal 100 karakter; menjadi kode workspace. |
| `project.name` | Tidak | Maksimal 255 karakter; kalau kosong dipakai `project.code`. |
| `ticket.id` | Tidak | Maksimal 100 karakter. |
| `ticket.status` | Tidak | Maksimal 100 karakter. Sebaiknya string enum. |
| `ticket.channel` | Tidak | Maksimal 100 karakter. Sebaiknya string enum. |
| `customer.id` | Tidak | Maksimal 191 karakter. |
| `customer.email` | Tidak | Wajib bila `ticket` dikirim; email valid. |
| `customer.wa` | Tidak | Maksimal 30 karakter; nomor WhatsApp dinormalkan ke format `62...`. |
| `customer.name` | Tidak | Maksimal 255 karakter; kalau kosong dipakai email. |

Aturan tambahan:

- `project.code` harus **stabil** dan tidak berubah-ubah. Nilai ini dipakai
  sebagai kode workspace di TransSurvey.
- `project.code` dan `user.email` dinormalisasi menjadi huruf kecil.
- `ticket` dan `customer` harus dikirim bersama. Kalau hanya salah satu yang
  dikirim, request ditolak.
- Kalau `ticket` dikirim, `customer` berada di dalam `ticket`.
- Jangan memakai kode workspace legacy untuk `project.code`; akan ditolak `409`.
- Jangan mengirim array tiket berisi lebih dari satu elemen.
- `customer.wa` opsional; kalau diisi, nomornya disimpan untuk kebutuhan
  broadcast CSAT.
- String tidak boleh memuat karakter kontrol.

---

## 5. Uji di staging

### 5.1 Jalur A (launch session API)

1. Tim TransSurvey mengisi `CXP_LAUNCH_API_KEY` di `.env` staging, lalu
   menjalankan `php artisan optimize:clear`.
2. TransCXP memanggil endpoint staging dan menerima `201` beserta `url`.
3. Buka `url` di browser:
   - login otomatis sebagai user `operation`;
   - workspace sesuai `project.code` aktif di header;
   - kalau tiket dikirim, browser diarahkan ke halaman **Kirim CSAT**;
   - data tiket/customer tersimpan di sesi, termasuk `customer.wa` bila dikirim.
4. Buka `url` yang sama sekali lagi → `401` (kode sekali pakai).
5. Tunggu lebih dari `expires_in`, lalu buka kode itu → `401` (kedaluwarsa).

### 5.2 Jalur B (JWT)

1. Tim TransSurvey mengisi shared secret (HS256) atau public key (ES256/RS256)
   di server staging, lalu menjalankan `php artisan optimize:clear`.
2. TransCXP membuat token baru, lalu membuka:

   ```text
   https://<staging-host>/cxp/launch?sso=<token>
   ```

3. Hasil yang diharapkan sama seperti Jalur A. Membuka URL yang sama sekali lagi
   menghasilkan `401`.

---

## 6. Yang perlu dikonfirmasi ke tim TransSurvey

1. Jalur yang dipilih: **A (launch session API)** atau **B (JWT)**.
2. `project.code` yang akan dipakai dan sifatnya stabil.
3. Mapping `status` dan `channel` kalau masih berupa angka
   (contoh: `1` = ?, `3` = ?, `2` = ?, `5` = ?).
4. Kalau memakai JWT: algoritma yang dipakai (HS256/ES256/RS256) dan public key
   (untuk ES256/RS256).
5. Kalau memakai JWT: 1 token contoh (boleh sudah kedaluwarsa) untuk uji
   struktur di staging.
6. Daftar user/project uji yang boleh dipakai di staging.
7. Konfirmasi siapa yang akan memanggil endpoint dari sisi TransCXP
   (backend, bukan frontend).

---

## 7. Keamanan

- Jangan menaruh API key atau shared secret di frontend, repo, screenshot, atau
  chat biasa.
- Selalu kirim token/kode lewat HTTPS.
- Jangan mencatat token penuh atau API key di log TransCXP/proxy.
- Buat `jti`/kode baru setiap klik; jangan mengandalkan cache halaman.
- Token JWT hanya berlaku sekitar 5 menit; kode launch session default 120 detik.
- Kalau API key atau secret perlu dirotasi, koordinasikan dengan tim TransSurvey
  agar ada masa transisi.
- Private key (ES256/RS256) tidak pernah dikirim ke TransSurvey.
