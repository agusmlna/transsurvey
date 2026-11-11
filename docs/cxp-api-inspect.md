# Uji Read-only API Tiket TransCXP

Dokumen internal untuk menguji API tiket TransCXP dari server TransSurvey.
Command `cxp:inspect` hanya membaca data: tidak menyimpan tiket/customer ke
database dan tidak mengirim email atau WhatsApp.

Sumber kontrak: `Api_Inquiry_TransCXP.pdf` (login, daftar tiket, detail tiket).

---

## 1. Isi kredensial di `.env`

Isi **hanya di server** (staging/production). Jangan menempel kredensial di chat,
repo, screenshot, atau tiket.

```dotenv
CXP_API_BASE_URL=https://crm.trans-cosmos.id
CXP_API_EMAIL=akun-api@trans-cosmos.id
CXP_API_PASSWORD=isi-password-di-sini
CXP_API_TIMEOUT=30
CXP_API_LOGIN_FIELD=email
CXP_API_AUTH_HEADER=Authorization
CXP_API_AUTH_PREFIX=Bearer
```

Catatan:

- Development: `https://crm.trans-cosmos.id`
- Production: `https://transcxp.trans-cosmos.id`
- `CXP_API_AUTH_PREFIX` boleh dikosongkan kalau TransCXP ternyata memakai
  header tanpa awalan (mis. `X-API-KEY: <token>`).
- `CXP_API_LOGIN_FIELD` default `email`. API resmi **memvalidasi field email**,
  jadi username murni akan ditolak dengan pesan
  `The email must be a valid email address.` Kalau akun API ternyata memakai
  username, coba `CXP_API_LOGIN_FIELD=username` atau opsi
  `--login-field=username`. Kalau tetap ditolak, minta tim TransCXP memastikan
  email yang terdaftar untuk akun API tersebut.

Setelah mengubah `.env`:

```bash
php artisan optimize:clear
```

Di staging, pastikan permission `.env` tetap `640 itsol:www-data`. Jangan pakai
`sed -i`.

---

## 2. Jalankan uji dasar

```bash
php artisan cxp:inspect --per-page=5
```

Yang diharapkan:

1. `Login berhasil.` dan token **tidak** ditampilkan.
2. Baris `Tiket    : /api/v2/tickets` dan tabel `Daftar tiket (/api/v2/tickets)`
   berisi tiket.
3. Ringkasan jumlah tiket tanpa email, tanpa nomor WA, dan `Solved` tanpa
   `end_date`.
4. Baris terakhir: `Read-only: tidak ada data yang disimpan...`.

Email dan nomor telepon disamarkan secara default. Untuk melihat nilai asli
(di server, jangan di screenshot):

```bash
php artisan cxp:inspect --per-page=5 --show-pii
```

---

## 3. Uji tiket tertentu dan detail

```bash
php artisan cxp:inspect --ticket=000033 --detail
```

- `--ticket` memfilter hasil daftar tiket berdasarkan `id_ticket`.
- `--detail` memanggil `CXP_API_DETAIL_PATH` untuk tiket tersebut.
- Ingat: `id_ticket` **tidak unik lintas project**. Detail bisa memuat beberapa
  project dengan nomor tiket yang sama.

Filter project dilakukan di sisi lokal, bukan API:

```bash
php artisan cxp:inspect --project=6 --per-page=10
```

---

## 4. Kalau autentikasi ditolak

Login memakai body `{email,password}` dan tidak memerlukan token. Endpoint tiket
memakai header yang sama dengan yang diuji di bawah (default
`Authorization: Bearer <token>`), jadi beberapa skema disediakan:

```bash
php artisan cxp:inspect --auth=bearer      # Authorization: Bearer <token>
php artisan cxp:inspect --auth=token       # Authorization: <token>
php artisan cxp:inspect --auth=x-api-key   # X-API-KEY: <token>
php artisan cxp:inspect --auth=x-token     # X-TOKEN: <token>
```

`--auth=config` (default) memakai `CXP_API_AUTH_HEADER` dan
`CXP_API_AUTH_PREFIX` dari `.env`.

Kalau login sendiri gagal:

| Status | Arti |
| --- | --- |
| `401 Incorrect password!` | Password salah. |
| `404 User not found!` | Email tidak terdaftar. |
| `422 Validation failed` | Email/password kosong atau format email salah. |

### Path endpoint tiket

PDF kontrak menyebut `GET /api/v1/dataTicketcxp`, tetapi path itu **sudah tidak
ada** di server TransCXP (production maupun development). Hasil probe
read-only tanpa kredensial:

| Path | Hasil |
| --- | --- |
| `/api/v1/dataTicketcxp` | `404` `text/html` |
| `/api/v1/detailChoose` | `404` `text/html` |
| `/api/v1/login-user` | `405` JSON (route ada, hanya POST) |
| `/api/v2/tickets` | `401 {"statusCode":401,"message":"The token is required!"}` |
| `/api/tickets` | `401 {"message":"Unauthenticated."}` (guard Sanctum, beda) |

Artinya endpoint tiket yang benar adalah **`/api/v2/tickets`** dengan header
`Authorization: Bearer <token>` dari `login-user`. Token yang sama juga dipakai
di sini: tanpa header balasannya `The token is required!`, dengan token salah
balasannya `Token does not valid!`.

Path bisa diubah tanpa mengubah kode:

```dotenv
CXP_API_TICKET_PATH=/api/v2/tickets
CXP_API_DETAIL_PATH=/api/v2/tickets
```

Endpoint detail belum ditemukan; defaultnya memakai path yang sama dengan
filter `id_ticket`. Kalau nanti tim TransCXP memberi path detail yang benar,
cukup isi `CXP_API_DETAIL_PATH`.

### Kalau kredensial berbentuk username

Sudah diuji di staging: API TransCXP **tidak menerima username**.

| Yang dikirim | Balasan |
| --- | --- |
| `email` berisi username | `The email must be a valid email address.` |
| `username` | `The email field is required.` |

Artinya endpoint `login-user` hanya punya field `email` dan mewajibkan format
email. `--login-field=username` hanya untuk diagnosis, bukan solusi.

Yang harus dilakukan: minta tim TransCXP memastikan **email yang terdaftar**
untuk akun API tersebut. Setelah dapat emailnya:

```dotenv
CXP_API_EMAIL=email-akun-api@domain
CXP_API_LOGIN_FIELD=email
```

lalu:

```bash
php artisan optimize:clear
php artisan cxp:inspect --per-page=5
```

---

## 5. Mode JSON untuk analisis

```bash
php artisan cxp:inspect --per-page=10 --json > /tmp/cxp-tickets.json
```

JSON sudah disanitasi (email/nomor disamarkan) dan menambahkan
`wa_normalized`. Jangan simpan hasil `--show-pii` di repo.

Kalau bentuk JSON berbeda dari PDF (kolom tabel penuh tanda `-`), lihat nama
field-nya dulu tanpa membocorkan nilai:

```bash
php artisan cxp:inspect --per-page=5 --keys
```

Mode ini hanya mencetak nama field dan tipenya (`string`, `int`, `null`,
`list`, `objek`), baik untuk envelope maupun tiap baris tiket.

---

## 6. Yang perlu dicatat untuk mode otomatis

Saat inspeksi, catat:

1. Apakah `wa` selalu terisi, atau perlu fallback `phone_no` / `mobile_phone`.
   Command sudah memakai urutan fallback tersebut.
2. Format `status_ticket` yang menandakan tiket selesai (`Solved`? `Closed`?).
3. Apakah `end_date` bisa kosong walaupun status `Solved` (terlihat di contoh
   PDF: ya).
4. Mapping `id_project` (angka) ke `workspaces.code` (string) di TransSurvey.
5. Nama field sumber CSAT: `source`, `category_name*`, `section_name`,
   `sub_section_name`, `subject`.
6. Apakah API bisa memfilter status/tanggal. Dari PDF, endpoint hanya menerima
   `id_ticket`, `page`, `per_page`; filter lain harus dilakukan di sisi
   TransSurvey.
7. Perilaku pagination: `per_page` tidak selalu diikuti dan `next_page_url`
   pada contoh memakai `127.0.0.1`. Gunakan `page` + `last_page`, jangan
   `next_page_url`.

---

## 7. Batasan keamanan

- Command tidak menulis token/password ke output atau log.
- Command tidak menyimpan data customer ke database.
- `--show-pii` hanya untuk server; jangan dijalankan di CI atau screenshot.
- Kredensial hanya di `.env` server, bukan di repo.
