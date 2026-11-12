# CSAT Link API — TransCXP → TransSurvey

Dokumen ini untuk tim TransCXP. Endpoint ini dipakai kalau **TransCXP ingin
mengirim sendiri tautan CSAT ke customer** (alur manual dari sisi TransCXP),
bukan meminta TransSurvey mengirim email.

| Item | Nilai |
| --- | --- |
| Method & endpoint | `POST /api/cxp/csat-links` |
| Autentikasi | API key yang sama dengan launch session (`Authorization: Bearer` atau `X-CXP-Key`) |
| Content-Type | `application/json` |
| Rate limit | 60 permintaan/menit per IP, 300/menit global |
| Mengirim email? | **Tidak.** TransSurvey hanya membuat tautan. |

> Kalau TransCXP ingin TransSurvey yang mengirim email CSAT, jangan pakai
> endpoint ini. Pakai alur `POST /api/cxp/launch-sessions` + halaman Kirim CSAT,
> atau autosend per jam yang sudah berjalan.

---

## 1. Alur

```text
Operation membuka tiket di TransCXP
        │
        ▼
Server TransCXP memanggil POST /api/cxp/csat-links
(body: user + project + tiket + customer)
        │
        ▼
TransSurvey membuat/mengambil undangan CSAT untuk tiket itu
        │
        ▼
TransCXP menerima { url, expires_at, created }
        │
        ▼
TransCXP mengirim url ke customer lewat kanalnya sendiri
```

Pemanggilan dilakukan di **backend TransCXP**, bukan di browser, karena API key
tidak boleh sampai ke client.

---

## 2. Header

```http
Authorization: Bearer <API_KEY>
Content-Type: application/json
Accept: application/json
```

atau:

```http
X-CXP-Key: <API_KEY>
```

---

## 3. Body

Bentuk payload **sama dengan launch session** supaya TransCXP tidak perlu
membangun dua kontrak.

```json
{
  "user": {
    "id": "5",
    "name": "Agent A",
    "email": "agent@email.com"
  },
  "project": {
    "code": "6",
    "name": "DIPOOPL"
  },
  "ticket": {
    "id": "075750",
    "status": "Close",
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
| `user.id` | Ya | ID user TransCXP; dipakai untuk audit log. |
| `user.email` | Ya | Email valid. |
| `project.code` | Ya | Harus sama dengan kode workspace di TransSurvey. |
| `ticket.id` | Ya | Satu tiket saja. |
| `ticket.status` | Tidak | Status tiket, mis. `Close`. |
| `ticket.channel` | Tidak | Kanal tiket, mis. `whatsapp`. |
| `ticket.customer.email` | Ya | Email customer; dipakai sebagai identitas client. |
| `ticket.customer.name` | Tidak | Kalau kosong dipakai email. |
| `ticket.customer.wa` | Tidak | Dinormalkan ke `62...`. Nilai tidak valid ditolak `422`. |

Aturan:

- `ticket.id` dan `ticket.customer.email` harus dikirim bersama.
- `project.code` **tidak** membuat workspace baru. Kode yang belum terdaftar
  ditolak `404`.
- Workspace harus punya template CSAT aktif sebagai default.

---

## 4. Balasan sukses

Tautan baru (`201 Created`):

```json
{
  "project": "6",
  "ticket_id": "075750",
  "survey_id": 2,
  "url": "https://<transsurvey-host>/s/9f2c...",
  "expires_at": "2026-11-19T11:26:51+07:00",
  "created": true
}
```

Tiket yang sudah punya tautan (`200 OK`): field sama, `created: false`, dan
`url` **tetap sama** dengan yang pernah dibuat.

| Field | Arti |
| --- | --- |
| `url` | Tautan CSAT yang dikirim ke customer. Jangan disimpan di log. |
| `expires_at` | Masa berlaku tautan. `null` = tanpa batas (pengaturan workspace). |
| `created` | `true` kalau undangan baru dibuat, `false` kalau dipakai ulang. |

Aturan tautan:

- Satu tiket + satu workspace = satu undangan. Panggilan berulang tidak
  membuat tautan baru.
- Tautan yang **sudah kedaluwarsa** diganti token baru. Tautan lama otomatis
  tidak berlaku.
- Tautan **tidak** mengirim email dan tidak membuat baris `email_deliveries`.
- Kalau customer sudah mengisi CSAT, permintaan ditolak `409` (`csat_completed`).

---

## 5. Error

| Status | `reason` | Arti |
| --- | --- | --- |
| `401` | `bad_api_key` | API key salah atau tidak dikirim. |
| `422` | `invalid_claim` | Field wajib kurang, email/WA tidak valid, atau tiket/customer tidak lengkap. |
| `404` | `workspace_not_found` | `project.code` belum terdaftar sebagai workspace. |
| `409` | `template_not_ready` | Workspace belum punya template CSAT aktif sebagai default. |
| `409` | `csat_completed` | Customer sudah mengisi CSAT untuk tiket ini. |
| `429` | — | Rate limit tercapai. |
| `500` | — | `CXP_LAUNCH_API_KEY` belum diisi di TransSurvey. |

Contoh:

```json
{
  "message": "Project TransCXP belum terdaftar di TransSurvey.",
  "reason": "workspace_not_found"
}
```

---

## 6. Contoh curl

```sh
curl -sS -X POST "https://<transsurvey-host>/api/cxp/csat-links" \
  -H "Authorization: Bearer $TRANSSURVEY_CXP_LAUNCH_API_KEY" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "user":    {"id":"5","name":"Agent A","email":"agent@email.com"},
    "project": {"code":"6","name":"DIPOOPL"},
    "ticket": {
      "id":"075750",
      "status":"Close",
      "channel":"whatsapp",
      "customer": {
        "name":"Customer A",
        "email":"customerA@email.com",
        "wa":"081234567890"
      }
    }
  }'
```

---

## 7. Hubungan dengan autosend

- Undangan yang dibuat lewat endpoint ini **tidak** akan dikirimi email oleh
  autosend, karena aturan "satu tiket satu tautan" sudah terpenuhi.
- Endpoint ini juga **tidak** menandai email sudah terkirim. Kalau operation
  nanti membuka halaman Kirim CSAT untuk tiket yang sama, TransSurvey masih
  bisa mengirim email dengan tautan yang sama (bukan tautan baru).
- Kalau nanti TransCXP berubah pikiran dan ingin email tetap dikirim
  TransSurvey, jangan pakai endpoint ini untuk tiket tersebut.
- Masa berlaku mengikuti pengaturan CSAT workspace (mis. 7 hari). Kalau
  workspace disetel tanpa batas, `expires_at` bernilai `null`.

---

## 8. Checklist uji

1. Panggil endpoint dengan `ticket` + `customer.email` yang valid.
2. Pastikan balasan `201` dan ada `url`.
3. Buka `url` di browser: halaman CSAT harus tampil.
4. Panggil ulang dengan tiket yang sama: `200`, `created: false`, `url` sama.
5. Pastikan tidak ada email baru yang terkirim (tidak ada baris
   `email_deliveries` baru).
6. Isi CSAT, lalu panggil lagi: harus `409 csat_completed`.
