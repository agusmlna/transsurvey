# Panduan Integrasi SSO TransCXP → TransSurvey (HS256)

Dokumen ini untuk tim TransCXP. Tujuannya supaya kalian bisa membuat token SSO
dan mengarahkan user ke TransSurvey tanpa perlu private/public key.

## 1. Ringkasan alur

```text
User klik tombol CSAT di TransCXP
        │
        ▼
Server TransCXP membuat JWT (HS256) berisi user + project
        │
        ▼
Browser diarahkan ke:
https://<transsurvey-host>/cxp/launch?sso=<JWT>
        │
        ▼
TransSurvey verifikasi tanda tangan, membuat/mencocokkan akun,
lalu login otomatis sebagai user operation.
```

Token dibuat di **backend TransCXP**, bukan di browser, karena shared secret
tidak boleh sampai ke client.

## 2. Yang perlu disiapkan

1. **Shared secret** untuk HS256.
   - Dibuat oleh tim TransSurvey.
   - Dikirim ke tim TransCXP lewat kanal aman (password manager / jalur terenkripsi).
   - Jangan ditulis di repo, chat, atau email biasa.
2. **URL staging TransSurvey** (diberikan tim TransSurvey).
3. **Kesepakatan `project.code`** yang stabil dan tidak berubah-ubah.

Di sisi TransSurvey, secret disimpan di `.env`:

```dotenv
CXP_SSO_SECRET=<secret-yang-sama>
CXP_SSO_ALGORITHMS=HS256
CXP_SSO_ISSUER=transcxp
CXP_SSO_AUDIENCE=transsurvey
CXP_SSO_REQUIRE_ISS_AUD=false
```

## 3. Payload token

### 3.1 Login saja (paling sederhana, disarankan untuk awal)

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

Tiket dan customer **tidak perlu** dikirim. Data tiket akan diambil lewat API
pada fase berikutnya.

### 3.2 Dengan satu tiket (opsional)

Kalau tombol CSAT ditekan dari satu tiket tertentu, kirim tiket itu saja:

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
      "email": "customerA@email.com"
    }
  }
}
```

Penting:

- `customer` diletakkan **di dalam** `ticket`, supaya pasangan tiket–customer jelas.
- Jangan mengirim **array berisi lebih dari satu tiket**. Token SSO adalah konteks
  login untuk satu tiket. Kalau butuh daftar tiket, itu lewat endpoint terpisah.
- Array berisi **tepat satu** tiket masih diterima, tetapi bentuk objek lebih disarankan.

### 3.3 Aturan klaim

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
| `customer.name` | Tidak | Kalau kosong, dipakai email. |

\* Wajib bila `CXP_SSO_REQUIRE_ISS_AUD=true`. Default TransSurvey: `false`,
jadi `iss`/`aud` boleh tidak dikirim. Tetap disarankan mengirim keduanya.

Batasan:

- Umur token maksimal **5 menit** (`exp - iat`). Jangan membuat token saat
  halaman dimuat jauh sebelum tombol diklik.
- Token maksimal 8192 karakter.
- String tidak boleh memuat karakter kontrol.
- `jti` harus baru setiap kali tombol diklik. Token yang sama tidak bisa dipakai dua kali.
- `project.code` dan `user.email` dinormalisasi menjadi huruf kecil oleh TransSurvey.

## 4. Contoh kode PHP (HS256)

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
    // 'ticket' => [
    //     'id' => 101,
    //     'status' => 'open',
    //     'channel' => 'whatsapp',
    //     'customer' => [
    //         'id' => 10,
    //         'name' => 'Customer A',
    //         'email' => 'customerA@email.com',
    //     ],
    // ],
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

## 5. Yang perlu dikirim ke tim TransSurvey

1. **Shared secret** (lewat kanal aman, jangan di repo/chat biasa).
2. Konfirmasi `project.code` yang akan dipakai dan sifatnya stabil.
3. Mapping `status` dan `channel` kalau masih berupa angka
   (contoh: `1` = ?, `3` = ?, `2` = ?, `5` = ?).
4. **1 token contoh** (boleh sudah kedaluwarsa) untuk uji struktur di staging.
5. Daftar user/project uji yang boleh dipakai di staging.
6. Konfirmasi siapa yang akan memanggil endpoint dari sisi TransCXP
   (backend, bukan frontend).

## 6. Uji di staging

1. Tim TransSurvey mengisi `CXP_SSO_SECRET` dan `CXP_SSO_ALGORITHMS=HS256`
   di server staging, lalu menjalankan `php artisan config:clear`.
2. TransCXP membuat token baru dengan secret yang sama.
3. Buka:

   ```text
   https://<staging-host>/cxp/launch?sso=<token>
   ```

4. Yang harus terlihat:
   - login otomatis sebagai user `operation`;
   - workspace sesuai `project.code` aktif di header;
   - kalau tiket dikirim, data tiket/customer tersimpan di sesi;
   - membuka URL yang sama sekali lagi menghasilkan 401 (token sekali pakai);
   - token kedaluwarsa menghasilkan 401.

## 7. Error yang mungkin

| Status | Arti |
| --- | --- |
| 401 | Token hilang, tidak valid, kedaluwarsa, tanda tangan salah, algoritma salah, `jti` sudah dipakai, atau berisi lebih dari satu tiket. |
| 403 | Akun SSO ada tetapi bukan role `operation`. |
| 409 | Email bentrok dengan akun lain, atau `project.code` memakai workspace legacy. |
| 429 | Rate limit `/cxp/launch` tercapai. |
| 500 | Konfigurasi SSO di TransSurvey belum lengkap. |

Pesan error tidak menampilkan token. Detail alasan dicatat di log server
TransSurvey dengan penanda `CXP SSO rejected`.

## 8. Alternatif tanpa JWT (kalau kalian tidak mau signing)

Kalau benar-benar tidak ingin membuat JWT, tersedia alur API key + kode sekali pakai:

```text
POST https://<transsurvey-host>/api/cxp/launch-sessions
Authorization: Bearer <CXP_LAUNCH_API_KEY>
Content-Type: application/json
```

Body-nya sama dengan payload di atas (tanpa `iss`, `aud`, `iat`, `exp`, `jti`):

```json
{
  "user":    { "id": "5", "name": "Agent A", "email": "agent@email.com" },
  "project": { "id": 23, "code": "23", "name": "Panasonic" }
}
```

Balasan:

```json
{
  "code": "64karakterhex...",
  "url": "https://<transsurvey-host>/cxp/launch?code=64karakterhex...",
  "expires_in": 120
}
```

Lalu arahkan browser ke `url`. Kode sekali pakai dan tidak membawa data customer
di URL.

Tapi untuk SSO, **JWT HS256 tetap jalur yang kami sarankan** karena token
diterbitkan langsung oleh TransCXP sebagai pemilik data.

## 9. Keamanan

- Jangan menaruh shared secret di frontend, repo, screenshot, atau chat biasa.
- Selalu kirim token lewat HTTPS.
- Jangan mencatat token penuh di log TransCXP/proxy.
- Buat `jti` baru setiap klik; jangan mengandalkan cache halaman.
- Token hanya berlaku sekitar 5 menit.
- Kalau secret perlu dirotasi, koordinasikan dengan tim TransSurvey agar
  ada masa transisi.
