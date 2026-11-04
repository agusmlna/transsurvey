# TransCXP → TransSurvey SSO (Fase 2)

Endpoint direct link dari TransCXP:

```text
GET https://<transsurvey-host>/cxp/launch?token=<JWT>
```

Token berisi identitas user, project, tiket, dan customer. Data customer/tiket **tidak boleh** dikirim sebagai query string terpisah. Setelah token valid, TransSurvey membuat/mencocokkan workspace dan akun `operation`, lalu login otomatis.

## 1. Konfigurasi

Isi di `.env` server (jangan commit nilainya):

```dotenv
# Pilih salah satu: HS256 (shared secret) atau RS256 (public key).
CXP_SSO_SECRET=
CXP_SSO_PUBLIC_KEY=
CXP_SSO_PUBLIC_KEY_PATH=
CXP_SSO_ALGORITHMS=HS256
CXP_SSO_ISSUER=transcxp
CXP_SSO_AUDIENCE=transsurvey
CXP_SSO_TTL=300
CXP_SSO_LEEWAY=60
CXP_SSO_REDIRECT_ROUTE=dashboard
CXP_ALLOW_OPERATION_PASSWORD=false
```

- `CXP_SSO_SECRET` dipakai untuk HS256. Nilai harus sama dengan yang dipegang TransCXP.
- Untuk RS256, isi `CXP_SSO_PUBLIC_KEY_PATH` (disarankan) atau `CXP_SSO_PUBLIC_KEY` dengan newline ditulis `\n`.
- `CXP_SSO_ALGORITHMS` adalah daftar yang dipisahkan koma, misalnya `HS256` atau `HS256,RS256`. Algoritma `none` selalu ditolak.
- `CXP_SSO_TTL` adalah umur token maksimum dalam detik. Token dengan `exp - iat` lebih besar dari nilai ini ditolak.
- `CXP_SSO_LEEWAY` adalah toleransi selisih jam dalam detik.
- `CXP_SSO_REDIRECT_ROUTE` adalah nama route tujuan setelah login. Fase 4 dapat mengganti ke halaman Kirim CSAT.
- `CXP_ALLOW_OPERATION_PASSWORD=false` menonaktifkan login password untuk akun operation/SSO.

Setelah mengubah `.env`:

```sh
php artisan config:clear
```

## 2. Format token

Header:

```json
{ "alg": "HS256", "typ": "JWT" }
```

Payload:

```json
{
  "iss": "transcxp",
  "aud": "transsurvey",
  "iat": 1762300000,
  "exp": 1762300300,
  "jti": "launch-8f2c1a7e",
  "user":     { "id": "CXP-USR-1", "name": "Nama Operator", "email": "operator@contoh.co.id" },
  "project":  { "code": "brand-a", "name": "Brand A" },
  "ticket":   { "id": "TCK-1001", "status": "open", "channel": "chat" },
  "customer": { "id": "CUST-9", "name": "Nama Customer", "email": "customer@contoh.co.id" }
}
```

Aturan klaim:

| Klaim | Wajib | Keterangan |
| --- | --- | --- |
| `iss` | Ya | Harus sama dengan `CXP_SSO_ISSUER`. |
| `aud` | Ya | String atau array; harus memuat `CXP_SSO_AUDIENCE`. |
| `iat` | Ya | Unix timestamp detik. |
| `exp` | Ya | Unix timestamp detik; harus lebih besar dari `iat`. |
| `nbf` | Tidak | Jika ada, token belum berlaku sebelum waktu ini. |
| `jti` | Ya | Unik, maksimal 255 karakter, hanya boleh dipakai sekali. |
| `user.id` | Ya | Kunci utama user TransCXP; dipakai untuk mencocokkan akun. |
| `user.email` | Ya | Email valid. Tidak dipakai untuk menautkan akun lama. |
| `user.name` | Tidak | Fallback ke email. |
| `project.code` | Ya | Kode project; menjadi `workspaces.code`. |
| `project.name` | Tidak | Fallback ke kode project. |
| `ticket.id` | Ya | Disimpan di sesi untuk Fase 4. |
| `ticket.status`, `ticket.channel` | Tidak | Disimpan di sesi. |
| `customer.email` | Ya | Email valid. |
| `customer.name` | Tidak | Fallback ke email. |
| `customer.id` | Tidak | Disimpan di sesi. |

Nilai string dibatasi panjangnya dan tidak boleh memuat karakter kontrol. `project.code` dinormalisasi menjadi huruf kecil.

## 3. Cara membuat token (contoh PHP)

```php
<?php

$now = time();
$header = ['alg' => 'HS256', 'typ' => 'JWT'];
$payload = [
    'iss' => 'transcxp',
    'aud' => 'transsurvey',
    'iat' => $now,
    'exp' => $now + 300,
    'jti' => bin2hex(random_bytes(16)),
    'user' => [
        'id' => 'CXP-USR-1',
        'name' => 'Nama Operator',
        'email' => 'operator@contoh.co.id',
    ],
    'project' => ['code' => 'brand-a', 'name' => 'Brand A'],
    'ticket' => ['id' => 'TCK-1001', 'status' => 'open', 'channel' => 'chat'],
    'customer' => [
        'id' => 'CUST-9',
        'name' => 'Nama Customer',
        'email' => 'customer@contoh.co.id',
    ],
];

$b64 = fn (mixed $value): string => rtrim(strtr(base64_encode(json_encode($value)), '+/', '-_'), '=');
$unsigned = $b64($header).'.'.$b64($payload);
$signature = hash_hmac('sha256', $unsigned, getenv('CXP_SSO_SECRET'), true);
$token = $unsigned.'.'.$b64($signature);

echo 'https://transsurvey.contoh.co.id/cxp/launch?token='.urlencode($token).PHP_EOL;
```

Token harus dibuat di server TransCXP, bukan di browser, karena secret tidak boleh sampai ke client.

## 4. Provisioning

Aturan yang diterapkan:

1. Workspace dicari berdasarkan `project.code`; jika belum ada, dibuat dengan `name = project.name`. Workspace baru otomatis mendapat bank pertanyaan default.
2. Akun dicari berdasarkan `external_source = transcxp` + `external_id = user.id`.
3. Jika belum ada, akun dibuat dengan role `operation`, password acak, dan ditambahkan ke workspace.
4. Jika email sudah dipakai akun lain, launch **ditolak** (HTTP 409). Akun lama tidak pernah ditautkan atau dinaikkan role-nya berdasarkan email.
5. Jika akun dengan `external_id` yang sama ternyata bukan `operation`, launch ditolak (HTTP 403).
6. Workspace aktif dan data tiket/customer disimpan di sesi (`cxp.context`) untuk dipakai halaman Kirim CSAT di Fase 4.
7. Login password untuk akun `operation`/SSO ditolak. Gunakan tombol dari TransCXP.

## 5. Keamanan dan error

- Tanda tangan diverifikasi sebelum klaim dibaca.
- Algoritma dibatasi oleh `CXP_SSO_ALGORITHMS`; `none` selalu ditolak.
- `jti` disimpan di cache dengan TTL sampai token kedaluwarsa. Token yang sama tidak bisa dipakai dua kali.
- Token yang gagal verifikasi tidak ditulis ke log. Log hanya memuat alasan, IP, dan user agent.
- Respons error tidak menampilkan token atau detail internal.

Status HTTP yang mungkin:

| Status | Arti |
| --- | --- |
| 401 | Token hilang, tidak valid, kedaluwarsa, tanda tangan salah, algoritma salah, atau `jti` sudah dipakai. |
| 403 | Akun SSO ada tetapi bukan role `operation`. |
| 409 | Email bentrok dengan akun lain, atau kode project memakai workspace legacy. |
| 429 | Rate limit `/cxp/launch` tercapai. |
| 500 | Konfigurasi SSO belum lengkap atau kesalahan server. |

Alasan detail dicatat di `storage/logs/laravel.log` dengan pesan `CXP SSO rejected` dan field `reason`.

## 6. Uji lokal

1. Isi `CXP_SSO_SECRET` di `.env` lokal, misalnya `local-test-secret`.
2. Jalankan `php artisan config:clear`.
3. Buat token dengan contoh di atas memakai secret yang sama.
4. Buka:

```text
http://127.0.0.1:8000/cxp/launch?token=<token>
```

5. Periksa:
   - login otomatis sebagai user `operation`;
   - workspace `brand-a` muncul di header;
   - data tiket/customer tersimpan di sesi;
   - membuka token yang sama sekali lagi menghasilkan 401 (anti-replay).

## 7. Catatan untuk tim TransCXP

- Kirim token lewat HTTPS.
- Jangan menaruh token di halaman yang di-cache atau di query string yang tercatat di log proxy. Endpoint TransSurvey mengirim `Cache-Control: no-store`, tetapi log sisi TransCXP/proxy tetap perlu diperhatikan.
- `jti` harus unik untuk setiap klik. Jika user mengulang, buat token baru.
- Token hanya berlaku sekitar 5 menit; jangan dibuat saat halaman dimuat jauh sebelum tombol diklik.
- Jika perlu rotasi secret, koordinasikan dengan tim TransSurvey. Selama masa transisi, secret lama dan baru dapat diterima dengan mengubah konfigurasi di kedua sisi.
