# Launch Session API — TransCXP → TransSurvey

Dokumen singkat untuk tim TransCXP. Isinya hanya kontrak endpoint yang dipakai
untuk membuka CSAT TransSurvey dari TransCXP.

Tidak perlu JWT dan tidak perlu key pair. Cukup panggil satu endpoint
server-to-server dengan API key, lalu arahkan browser user ke `url` yang dibalas.

---

## 1. Endpoint

```text
POST http://10.0.24.118/api/cxp/launch-sessions
```

- `10.0.24.118` adalah host staging. Untuk production nanti memakai domain
  production dengan HTTPS.
- Endpoint ini **server-to-server**. Jangan dipanggil dari JavaScript/browser.
- Endpoint browser yang dibuka user adalah `url` pada balasan, bukan endpoint ini.

## 2. Autentikasi

Kirim API key di header:

```http
Authorization: Bearer <API_KEY>
```

atau:

```http
X-CXP-Key: <API_KEY>
```

API key dikirim terpisah lewat kanal aman. Simpan di environment variable atau
secret manager server, bukan di repo/frontend.

## 3. Header

```http
Content-Type: application/json
Accept: application/json
```

## 4. Body — login saja (paling sederhana)

```json
{
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

## 5. Body — dengan satu tiket (opsional)

```json
{
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

Aturan:

- `user.id`, `user.email`, dan `project.code` wajib.
- `ticket` dan `customer` harus dikirim bersama; `customer` berada di dalam `ticket`.
- `customer.wa` opsional. Isi nomor WhatsApp customer (contoh `081234567890`);
  TransSurvey menormalkannya menjadi format `62...` untuk kebutuhan broadcast.
- Jangan mengirim lebih dari satu tiket dalam satu request.
- Untuk tes pertama, cukup kirim `user` + `project` (tanpa tiket).

## 6. Balasan sukses — `201 Created`

```json
{
  "code": "84dcf11904405b4036b5dea7413377e1f6beea69ca39fd729fed86d00406e19e",
  "url": "http://10.0.24.118/cxp/launch?code=84dcf11904405b4036b5dea7413377e1f6beea69ca39fd729fed86d00406e19e",
  "expires_in": 120
}
```

Langkah berikutnya di sisi TransCXP: **redirect browser (302)** ke `url`.

```php
header('Location: '.$response['url'], true, 302);
exit;
```

Jangan memakai AJAX/`fetch` untuk membuka `url`; redirect harus top-level agar
sesi login TransSurvey tersimpan di browser.

## 7. Aturan kode

- 64 karakter hex, **sekali pakai**.
- Berlaku `expires_in` detik (default 120).
- Buat kode baru untuk setiap klik tombol.
- Jangan menyimpan atau memakai ulang `url`.
- Tidak ada email customer di URL.

## 8. Error

| Status | Arti | Yang dilakukan TransCXP |
| --- | --- | --- |
| `401` | API key salah/tidak dikirim | Cek konfigurasi API key |
| `422` | Payload tidak valid | Cek field wajib dan format email |
| `429` | Rate limit tercapai | Beri jeda, jangan retry agresif |
| `500` | Konfigurasi API key di TransSurvey belum lengkap | Hubungi tim TransSurvey |

Contoh `422`:

```json
{
  "message": "Data pada token SSO tidak lengkap.",
  "reason": "invalid_claim"
}
```

Rate limit: 60 request/menit per IP dan 300 request/menit global.

## 9. Contoh curl

```sh
curl -sS -X POST "http://10.0.24.118/api/cxp/launch-sessions" \
  -H "Authorization: Bearer $TRANSSURVEY_CXP_LAUNCH_API_KEY" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "user":    { "id": "5", "name": "Agent A", "email": "agent@email.com" },
    "project": { "id": 23, "code": "23", "name": "Panasonic" }
  }'
```

## 10. Contoh PHP (Laravel)

```php
use Illuminate\Support\Facades\Http;

$payload = [
    'user' => [
        'id'    => (string) $user->id,
        'name'  => $user->name,
        'email' => $user->email,
    ],
    'project' => [
        'id'   => $project->id,
        'code' => (string) $project->code,
        'name' => $project->name,
    ],
];

$response = Http::withToken(config('services.transsurvey.cxp_launch_api_key'))
    ->acceptJson()
    ->post(config('services.transsurvey.url').'/api/cxp/launch-sessions', $payload)
    ->throw();

return redirect()->away($response->json('url'));
```

Konfigurasi:

```php
// config/services.php
'transsurvey' => [
    'url' => env('TRANSSURVEY_URL'), // http://10.0.24.118
    'cxp_launch_api_key' => env('TRANSSURVEY_CXP_LAUNCH_API_KEY'),
],
```

## 11. Checklist uji staging

1. Simpan `TRANSSURVEY_URL` dan API key di environment server.
2. Panggil endpoint dengan `user` + `project`.
3. Pastikan balasan `201` dan ada `url`.
4. Redirect browser ke `url`.
5. Pastikan login otomatis dan workspace sesuai `project.code` muncul.
6. Tekan tombol lagi → harus membuat kode baru dan tetap bisa masuk.
7. Buka `url` lama sekali lagi → harus `401` (kode sekali pakai).

## 12. Yang perlu dikonfirmasi

- Apakah server TransCXP bisa mengakses `http://10.0.24.118`?
- Apakah browser agent juga bisa membuka alamat yang sama?
- `project.code` yang akan dipakai (harus stabil).
- Mapping `status` dan `channel` tiket (kalau masih berupa angka).
- PIC backend yang memegang API key.
