# TransCXP → TransSurvey SSO (Fase 2)

Endpoint direct link dari TransCXP:

```text
GET https://<transsurvey-host>/cxp/launch?token=<JWT>
GET https://<transsurvey-host>/cxp/launch?sso=<JWT>
```

Kedua nama parameter diterima. `?sso=` disediakan untuk mengikuti pola CakrawalaHub.

Token berisi identitas user, project, tiket, dan customer. Data customer/tiket **tidak boleh** dikirim sebagai query string terpisah. Setelah token valid, TransSurvey membuat/mencocokkan workspace dan akun `operation`, lalu login otomatis.

## 1. Konfigurasi

Isi di `.env` server (jangan commit nilainya):

```dotenv
# Pilih salah satu: HS256 (shared secret), RS256, atau ES256 (public key).
CXP_SSO_SECRET=
CXP_SSO_PUBLIC_KEY=
CXP_SSO_PUBLIC_KEY_PATH=
CXP_SSO_ALGORITHMS=ES256
CXP_SSO_ISSUER=transcxp
CXP_SSO_AUDIENCE=transsurvey
CXP_SSO_REQUIRE_ISS_AUD=false
CXP_SSO_API_TOKEN_ID=
CXP_SSO_REPLAY_FALLBACK=false
CXP_SSO_TTL=300
CXP_SSO_LEEWAY=60
CXP_SSO_REDIRECT_ROUTE=dashboard
CXP_ALLOW_OPERATION_PASSWORD=false
```

- `CXP_SSO_SECRET` dipakai untuk HS256. Nilai harus sama dengan yang dipegang TransCXP. HS256 adalah pilihan paling sederhana: tidak perlu key pair, cukup satu string rahasia di kedua sisi.
- Untuk RS256/ES256, isi `CXP_SSO_PUBLIC_KEY_PATH` (disarankan) atau `CXP_SSO_PUBLIC_KEY` dengan newline ditulis `\n`.
- `CXP_SSO_ALGORITHMS` adalah daftar yang dipisahkan koma, misalnya `ES256`, `HS256`, atau `ES256,HS256`. Algoritma `none` selalu ditolak.
- `CXP_SSO_REQUIRE_ISS_AUD=false` membuat `iss`/`aud` opsional (pola CakrawalaHub). Kalau klaim itu tetap dikirim, nilainya tetap divalidasi.
- `CXP_SSO_API_TOKEN_ID` opsional. Jika diisi, token wajib memuat `apiTokenId` dengan nilai yang sama. Kosongkan untuk mengabaikan.
- `CXP_SSO_REPLAY_FALLBACK=false` mewajibkan `jti`. Jika `true`, token tanpa `jti` masih diterima dan anti-replay memakai hash token.
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
{ "alg": "ES256", "typ": "JWT" }
```

Payload:

```json
{
  "apiTokenId": 1,
  "iss": "transcxp",
  "aud": "transsurvey",
  "iat": 1762300000,
  "exp": 1762300300,
  "jti": "launch-8f2c1a7e",
  "email": "operator@contoh.co.id",
  "user":     { "id": "CXP-USR-1", "name": "Nama Operator", "email": "operator@contoh.co.id" },
  "project":  { "id": 23, "code": "23", "name": "Brand A" },
  "ticket": {
    "id": "TCK-1001",
    "status": "open",
    "channel": "chat",
    "customer": { "id": "CUST-9", "name": "Nama Customer", "email": "customer@contoh.co.id" }
  }
}
```

`email` di level atas adalah bentuk yang dipakai CakrawalaHub. TransSurvey menyalinnya ke `user.email` bila `user.email` tidak ada. Format baru tetap memakai `user.email`.

Untuk **login saja**, `ticket` dan `customer` boleh tidak dikirim. Token minimal cukup berisi `user` dan `project`:

```json
{
  "apiTokenId": 1,
  "iss": "transcxp",
  "aud": "transsurvey",
  "iat": 1762300000,
  "exp": 1762300300,
  "jti": "launch-8f2c1a7e",
  "user":    { "id": "CXP-USR-1", "name": "Nama Operator", "email": "operator@contoh.co.id" },
  "project": { "id": 23, "code": "23", "name": "Brand A" }
}
```

Data tiket tidak perlu dibawa token SSO. Halaman Kirim CSAT akan mengambil tiket lewat API pada Fase 4. Kalau `ticket` tetap dikirim, `customer` wajib ikut (dan sebaliknya); satu tanpa yang lain ditolak.

`customer` sebaiknya berada **di dalam** `ticket` agar pasangan tiket–customer tidak ambigu. TransSurvey menerima tiga bentuk berikut:

| Bentuk `ticket` | Contoh | Status |
| --- | --- | --- |
| Objek dengan `customer` di dalam | `"ticket": { "id": ..., "customer": {...} }` | Disarankan |
| Array berisi tepat satu tiket | `"ticket": [ { "id": ..., "customer": {...} } ]` | Diterima |
| Format lama: `ticket` objek + `customer` di level atas | `"ticket": {...}, "customer": {...}` | Tetap didukung |

Array yang berisi **lebih dari satu tiket** ditolak (`ambiguous_ticket`, HTTP 401) karena token SSO adalah konteks login untuk satu tiket. Daftar tiket, bila dibutuhkan, harus dikirim lewat endpoint terpisah, bukan di token SSO.

Aturan klaim:

| Klaim | Wajib | Keterangan |
| --- | --- | --- |
| `iss` | Ya* | Harus sama dengan `CXP_SSO_ISSUER`. |
| `aud` | Ya* | String atau array; harus memuat `CXP_SSO_AUDIENCE`. |
| `apiTokenId` | Tidak | Jika `CXP_SSO_API_TOKEN_ID` diisi, nilainya harus sama. |
| `iat` | Ya | Unix timestamp detik. |
| `exp` | Ya | Unix timestamp detik; harus lebih besar dari `iat`. |
| `nbf` | Tidak | Jika ada, token belum berlaku sebelum waktu ini. |
| `jti` | Ya** | Unik, maksimal 255 karakter, hanya boleh dipakai sekali. |
| `email` | Tidak | Disalin ke `user.email` bila klaim itu kosong. |
| `user.id` | Ya | Kunci utama user TransCXP; dipakai untuk mencocokkan akun. |
| `user.email` | Ya | Email valid. Tidak dipakai untuk menautkan akun lama. |
| `user.name` | Tidak | Fallback ke email. |
| `project.code` | Ya | Kode project; menjadi `workspaces.code`. |
| `project.id`, `project.name` | Tidak | `name` menjadi nama workspace; fallback ke kode project. |
| `ticket.id` | Tidak | Opsional. Jika dikirim, `customer.email` wajib ikut. Disimpan di sesi untuk Fase 4. |
| `ticket.status`, `ticket.channel` | Tidak | Disimpan di sesi. String enum lebih disarankan daripada kode angka. |
| `ticket.customer` | Tidak | Bentuk yang disarankan; customer menempel pada tiketnya. |
| `customer.email` | Tidak | Wajib bila `ticket` dikirim. Email valid; boleh di dalam `ticket.customer` atau di level atas. |
| `customer.name` | Tidak | Fallback ke email. |
| `customer.id` | Tidak | Disimpan di sesi. |

\* Wajib bila `CXP_SSO_REQUIRE_ISS_AUD=true`. Bila `false`, klaim boleh tidak dikirim.
\** Wajib bila `CXP_SSO_REPLAY_FALLBACK=false` (default).

Nilai string dibatasi panjangnya dan tidak boleh memuat karakter kontrol. `project.code` dinormalisasi menjadi huruf kecil.

## 3. Cara membuat token (contoh PHP)

### ES256 (pola CakrawalaHub)

TransCXP memegang **private key**, TransSurvey hanya memegang **public key**. Pakai key pair baru khusus TransSurvey, jangan memakai key Cakrawala yang sudah ada.

```php
<?php

$privateKeyPath = '/path/aman/transsurvey-es256-private.pem'; // jangan di repo
$privateKey = openssl_pkey_get_private(file_get_contents($privateKeyPath));

$now = time();
$header = ['alg' => 'ES256', 'typ' => 'JWT'];
$payload = [
    'apiTokenId' => $apiTokenId,
    'iss' => 'transcxp',
    'aud' => 'transsurvey',
    'iat' => $now,
    'exp' => $now + 300,
    'jti' => bin2hex(random_bytes(16)),
    'email' => $email,
    'user' => [
        'id' => $userId,
        'name' => $userName,
        'email' => $email,
    ],
    'project' => [
        'id' => $projectId,
        'code' => (string) $projectId,
        'name' => $projectName,
    ],
    'ticket' => [
        'id' => $ticketId,
        'status' => $ticketStatus,
        'channel' => $ticketChannel,
        'customer' => [
            'id' => $customerId,
            'name' => $customerName,
            'email' => $customerEmail,
        ],
    ],
];

$b64 = fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
$json = fn (array $value): string => $b64(json_encode($value, JSON_UNESCAPED_SLASHES));

$unsigned = $json($header).'.'.$json($payload);

// OpenSSL menghasilkan signature DER; JWT ES256 memakai raw R||S (64 byte).
openssl_sign($unsigned, $der, $privateKey, OPENSSL_ALGO_SHA256);
$token = $unsigned.'.'.$b64(derToRaw($der));

function derToRaw(string $der, int $size = 32): string
{
    $pos = 0;
    if (ord($der[$pos++]) !== 0x30) {
        throw new RuntimeException('Signature bukan DER sequence.');
    }

    $length = ord($der[$pos++]);
    if ($length & 0x80) {
        $count = $length & 0x7f;
        $length = 0;
        while ($count-- > 0) {
            $length = ($length << 8) | ord($der[$pos++]);
        }
    }

    $readInteger = function () use ($der, &$pos): string {
        if (ord($der[$pos++]) !== 0x02) {
            throw new RuntimeException('Signature bukan DER integer.');
        }

        $length = ord($der[$pos++]);
        if ($length & 0x80) {
            $count = $length & 0x7f;
            $length = 0;
            while ($count-- > 0) {
                $length = ($length << 8) | ord($der[$pos++]);
            }
        }

        $value = substr($der, $pos, $length);
        $pos += $length;

        return ltrim($value, "\x00");
    };

    return str_pad($readInteger(), $size, "\x00", STR_PAD_LEFT)
        .str_pad($readInteger(), $size, "\x00", STR_PAD_LEFT);
}

echo 'https://transsurvey.contoh.co.id/cxp/launch?sso='.urlencode($token).PHP_EOL;
```

TransCXP sudah punya service ES256 untuk CakrawalaHub. Yang perlu diubah hanya isi `$payload` dan URL tujuan; bagian signing tidak berubah.

### HS256 (khusus uji lokal)

```php
$header = ['alg' => 'HS256', 'typ' => 'JWT'];
$payload = [/* sama seperti di atas */];

$b64 = fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
$json = fn (array $value): string => $b64(json_encode($value, JSON_UNESCAPED_SLASHES));

$unsigned = $json($header).'.'.$json($payload);
$signature = hash_hmac('sha256', $unsigned, getenv('CXP_SSO_SECRET'), true);

echo 'http://127.0.0.1:8000/cxp/launch?token='.$unsigned.'.'.$b64($signature).PHP_EOL;
```

Token harus dibuat di server TransCXP, bukan di browser, karena private key/secret tidak boleh sampai ke client.

## 4. Provisioning

Aturan yang diterapkan:

1. Workspace dicari berdasarkan `project.code`; jika belum ada, dibuat dengan `name = project.name`. Workspace baru otomatis mendapat bank pertanyaan default.
2. Akun dicari berdasarkan `external_source = transcxp` + `external_id = user.id`.
3. Jika belum ada, akun dibuat dengan role `operation`, password acak, dan ditambahkan ke workspace.
4. Jika email sudah dipakai akun lain, launch **ditolak** (HTTP 409). Akun lama tidak pernah ditautkan atau dinaikkan role-nya berdasarkan email.
5. Jika akun dengan `external_id` yang sama ternyata bukan `operation`, launch ditolak (HTTP 403).
6. Workspace aktif disimpan di sesi (`cxp.context`). Jika token membawa `ticket`, data tiket/customer ikut disimpan untuk halaman Kirim CSAT. Jika tidak, halaman itu mengambil data tiket lewat API pada Fase 4.
7. Login password untuk akun `operation`/SSO ditolak. Gunakan tombol dari TransCXP.

## 5. Keamanan dan error

- Tanda tangan diverifikasi sebelum klaim dibaca.
- Algoritma dibatasi oleh `CXP_SSO_ALGORITHMS`; `none` selalu ditolak.
- ES256 memakai kurva P-256. Signature JWT berbentuk raw `R||S` (64 byte) dan dikonversi ke DER sebelum diverifikasi OpenSSL. Token dengan signature bukan 64 byte ditolak.
- `iss`/`aud` divalidasi bila dikirim. Kewajibannya diatur `CXP_SSO_REQUIRE_ISS_AUD`.
- `apiTokenId` divalidasi bila `CXP_SSO_API_TOKEN_ID` diisi.
- `jti` disimpan di cache dengan TTL sampai token kedaluwarsa. Token yang sama tidak bisa dipakai dua kali. Jika `CXP_SSO_REPLAY_FALLBACK=true`, token tanpa `jti` memakai hash token sebagai kunci anti-replay.
- Token yang gagal verifikasi tidak ditulis ke log. Log hanya memuat alasan, IP, dan user agent.
- Respons error tidak menampilkan token atau detail internal.

Status HTTP yang mungkin:

| Status | Arti |
| --- | --- |
| 401 | Token hilang, tidak valid, kedaluwarsa, tanda tangan salah, algoritma salah, `jti` sudah dipakai, atau berisi lebih dari satu tiket. |
| 403 | Akun SSO ada tetapi bukan role `operation`. |
| 409 | Email bentrok dengan akun lain, atau kode project memakai workspace legacy. |
| 429 | Rate limit `/cxp/launch` tercapai. |
| 500 | Konfigurasi SSO belum lengkap atau kesalahan server. |

Alasan detail dicatat di `storage/logs/laravel.log` dengan pesan `CXP SSO rejected` dan field `reason`.

## 6. Uji lokal

### ES256

1. Buat key pair P-256 (sekali saja, jangan commit private key):

   ```sh
   openssl ecparam -name prime256v1 -genkey -noout -out transcxp-es256-private.pem
   openssl ec -in transcxp-es256-private.pem -pubout -out transcxp-es256-public.pem
   ```

2. Isi `.env` lokal:

   ```dotenv
   CXP_SSO_SECRET=
   CXP_SSO_PUBLIC_KEY_PATH=/path/ke/transcxp-es256-public.pem
   CXP_SSO_ALGORITHMS=ES256
   ```

3. Jalankan `php artisan config:clear`.
4. Buat token ES256 dengan contoh di atas memakai private key.
5. Buka `http://127.0.0.1:8000/cxp/launch?sso=<token>`.
6. Periksa:
   - login otomatis sebagai user `operation`;
   - workspace sesuai `project.code` muncul di header;
   - data tiket/customer tersimpan di sesi;
   - membuka token yang sama sekali lagi menghasilkan 401 (anti-replay);
   - mengganti public key dengan key lain menghasilkan 401 (tanda tangan salah).

### HS256

1. Isi `CXP_SSO_SECRET` di `.env` lokal, misalnya `local-test-secret`.
2. Jalankan `php artisan config:clear`.
3. Buat token dengan contoh HS256 di atas memakai secret yang sama.
4. Buka `http://127.0.0.1:8000/cxp/launch?token=<token>`.
5. Periksa hal yang sama seperti uji ES256 di atas.

## 7. Catatan untuk tim TransCXP

- Kirim token lewat HTTPS.
- Jangan menaruh token di halaman yang di-cache atau di query string yang tercatat di log proxy. Endpoint TransSurvey mengirim `Cache-Control: no-store`, tetapi log sisi TransCXP/proxy tetap perlu diperhatikan.
- `jti` harus unik untuk setiap klik. Jika user mengulang, buat token baru.
- Token hanya berlaku sekitar 5 menit; jangan dibuat saat halaman dimuat jauh sebelum tombol diklik.
- Algoritma yang dipakai: **ES256** (ECDSA P-256, `prime256v1`). Kirim **public key** (PEM) ke tim TransSurvey, dan simpan **private key** hanya di server TransCXP.
- Pakai **key pair baru khusus TransSurvey**, jangan memakai key CakrawalaHub. Public key Cakrawala tidak boleh dipakai ulang untuk TransSurvey.
- Signature JWT ES256 harus berupa raw `R||S` (64 byte), bukan DER. Contoh konversi ada di bagian 3.
- Setelah payload selesai diubah, kirim **1 token contoh** agar bisa diuji di staging sebelum production. Jangan kirim private key.
- Jika perlu rotasi key, koordinasikan dengan tim TransSurvey agar public key lama dan baru dapat diterima selama masa transisi.
