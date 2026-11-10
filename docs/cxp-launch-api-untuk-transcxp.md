# Launch Session API — TransCXP → TransSurvey

Dokumen singkat untuk tim TransCXP. Isinya hanya kontrak endpoint yang dipakai
untuk membuka CSAT TransSurvey dari TransCXP.

Tidak perlu JWT dan tidak perlu key pair. Cukup panggil satu endpoint
server-to-server dengan API key, lalu arahkan browser user ke `url` yang dibalas.

---

## 1. Ringkasan dan endpoint

| Item | Nilai |
| --- | --- |
| Method & endpoint | `POST /api/cxp/launch-sessions` |
| Host staging | `http://10.0.24.118` |
| Host production | Domain production (HTTPS), menyusul |
| Autentikasi | API key (`Authorization: Bearer` atau `X-CXP-Key`) |
| Dipanggil dari | Backend TransCXP (server-to-server) |
| Balasan sukses | `201 Created` berisi `code`, `url`, `expires_in` |
| Langkah berikutnya | Redirect browser (302) ke `url` |
| Tujuan setelah `url` dibuka | Halaman Kirim CSAT bila payload membawa tiket; dashboard bila login saja |

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

| Field | Wajib | Keterangan |
| --- | --- | --- |
| `user.id` | Ya | ID user di TransCXP. |
| `user.email` | Ya | Email valid. |
| `user.name` | Tidak | Kalau kosong dipakai email. |
| `project.code` | Ya | Harus stabil, tidak berubah-ubah. |
| `project.name` | Tidak | Kalau kosong dipakai `project.code`. |
| `ticket` | Tidak | Satu objek tiket saja. |
| `ticket.id` | Tidak | ID tiket di TransCXP. |
| `ticket.status` | Tidak | Status tiket, mis. `open`, `solved`. |
| `ticket.channel` | Tidak | Kanal tiket, mis. `whatsapp`, `email`. |
| `ticket.customer` | Wajib bila `ticket` dikirim | Berada di dalam `ticket`, minimal `email`. |
| `customer.wa` | Tidak | Nomor WhatsApp customer; dinormalkan ke format `62...`. |

Aturan:

- `user.id`, `user.email`, dan `project.code` wajib.
- `ticket` dan `customer` harus dikirim bersama; `customer` berada di dalam `ticket`.
- `customer.wa` opsional. Isi nomor WhatsApp customer (contoh `081234567890`);
  TransSurvey menormalkannya menjadi format `62...` untuk kebutuhan broadcast.
  Nilai yang tidak valid ditolak `422` dengan alasan `invalid_claim`.
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

### 6.1 Ke mana browser diarahkan?

Setelah `url` dibuka, TransSurvey memvalidasi kode, login otomatis, lalu
mengarahkan browser sesuai payload:

| Payload | Tujuan |
| --- | --- |
| `user` + `project` saja | Dashboard |
| `user` + `project` + `ticket` + `customer.email` | Halaman Kirim CSAT (`/csat/send`) |

Di halaman Kirim CSAT, operation melihat ringkasan tiket dan customer, memilih
template, lalu mengirim CSAT. Pengiriman CSAT tetap lewat **email**. Nomor
WhatsApp yang dikirim ikut disimpan untuk kebutuhan broadcast berikutnya.

### 6.2 Memastikan payload diterima

Balasan `201` berarti payload lolos validasi dan kode berhasil dibuat. Body
request **tidak** disimpan di access log, jadi access log hanya menunjukkan
status HTTP. Untuk memastikan `customer.wa` ikut, buka halaman Kirim CSAT
setelah redirect: nomor WhatsApp tampil di ringkasan customer dan tersimpan di
data client saat CSAT dikirim.

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
6. Kalau payload membawa tiket, pastikan browser mendarat di halaman **Kirim
   CSAT** dan data tiket/customer tampil.
7. Kalau `customer.wa` dikirim, pastikan nomor WhatsApp tampil di halaman Kirim
   CSAT dan tersimpan sebagai data client setelah CSAT dikirim.
8. Tekan tombol lagi → harus membuat kode baru dan tetap bisa masuk.
9. Buka `url` lama sekali lagi → harus `401` (kode sekali pakai).

## 12. Yang masih perlu dikonfirmasi

Konektivitas staging sudah terbukti dari request `201` yang diterima. Yang masih
perlu disepakati sebelum production:

- Domain production TransSurvey yang akan dipakai (HTTPS).
- `project.code` final untuk setiap project (harus stabil).
- Mapping nilai `status` dan `channel` tiket dari TransCXP (kalau masih berupa angka).
- Apakah `customer.wa` selalu tersedia di payload tiket.
- PIC backend yang memegang API key di production.
