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

Filter yang **didukung server** (diuji di production):

```bash
php artisan cxp:inspect --status=Close --from=2026-11-01 --to=2026-11-10 --per-page=20
```

| Opsi | Query yang dikirim |
| --- | --- |
| `--status=Close` | `status=Close` |
| `--from=2026-11-01` | `start_date=2026-11-01` |
| `--to=2026-11-10` | `end_date=2026-11-10` |
| `--ticket=000001` | `id_ticket=000001` |
| `--page=2` | `page=2` |

Parameter yang **diabaikan** server: `status_name`, `id_project`, `search`,
`per_page` di atas 20.

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

### Bentuk respons `/api/v2/tickets`

Berbeda dari contoh di PDF:

| Bagian | Isi |
| --- | --- |
| Envelope | `statusCode`, `message`, `data`, `meta` (bukan `pagination`) |
| Baris tiket | `id`, `id_ticket`, `subject`, `source`, `status_name`, `start_date`, `end_date`, `customer`, `agent`, dll |
| Customer | objek bersarang (`customer.email`, `customer.wa`, ...) |

Kolom tabel dipetakan dengan beberapa kemungkinan nama field sekaligus:

| Kolom | Urutan field yang dicoba |
| --- | --- |
| Status | `status_ticket` → `status_name` → `status` |
| Email | `email` → `customer.email` → `customer_email` |
| WA | `wa` → `phone_no` → `mobile_phone` → `customer.wa` → `customer.phone_no` → `customer.mobile_phone` |
| Selesai | `end_date` → `finish_date` → `closed_date` |
| Project | `id_project` → `project.id` → `customer.id_project` |

Jadi command tetap jalan untuk kedua bentuk respons. Kalau ada field yang
masih `-`, jalankan `--keys` untuk melihat nama aslinya (termasuk yang
bersarang seperti `customer.email`).

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
`list`, `objek`), baik untuk envelope maupun tiap baris tiket. Field bersarang
ikut ditampilkan dengan titik, mis. `customer.email (string)`.

Untuk mengetahui apakah server mendukung filter, jalankan:

```bash
php artisan cxp:inspect --probe --per-page=20
```

Perintah ini mengirim beberapa kombinasi query (`status`, `start_date`,
`end_date`, `update_date`, `order_by`, `search`, dst.) lalu menampilkan
**jumlah hasilnya saja**, ditambah rentang `start_date`/`update_date` baris yang
dikembalikan. Kolom `Sesuai` menunjukkan apakah semua `start_date` baris berada
di dalam rentang yang diminta — ini cara memastikan filter tanggal berlaku pada
field apa. Kalau `Total` tidak berubah antar baris, parameter itu diabaikan
server.

---

## 6. Yang perlu dicatat untuk mode otomatis

Hasil inspeksi `/api/v2/tickets` (production, 10 Nov 2026):

- Total 1.042 tiket, 20 baris per halaman, 53 halaman.
- Urutan default: tiket terbaru lebih dulu (`id` menurun).
- Status yang terlihat: `Open` dan `Close` — **bukan** `Solved`.
- `end_date` **`null` untuk semua baris**, termasuk yang berstatus `Close`.
- **Project tidak tersedia** di respons: tidak ada `id_project` maupun
  `project_name`, termasuk di dalam `customer`.
- Email ada di `customer.email`, nomor di `customer.wa` /
  `customer.phone_no` / `customer.mobile_phone`.
- Dari 20 tiket terbaru: 17 tanpa email, 4 tanpa nomor WA.
- Filter server yang terbukti bekerja: `status`, `id_ticket`, `start_date`.
  Contoh: `status=Close` → 464 tiket.
- `end_date` **diabaikan** saat dikirim sendiri (total tetap 1042). Filter
  tanggal hanya menyaring tanggal **dibuat** (`start_date`), bukan tanggal
  tutup. Tiket lama yang baru ditutup tidak akan muncul di jendela tanggal
  terbaru.
- `per_page` diikuti sampai 20; nilai 100 tetap mengembalikan 20 baris.
- Urutan default menurun berdasarkan `start_date`, jadi tiket `Close` terbaru
  berdasarkan tanggal tutup tidak selalu ada di halaman awal.

Saat inspeksi, catat:

1. Apakah `wa` selalu terisi, atau perlu fallback `phone_no` / `mobile_phone`.
   Command sudah memakai urutan fallback tersebut.
2. Status mana yang menandakan tiket selesai (`Close`? `Open`?). Ini yang
   dipakai mode otomatis, karena `end_date` tidak bisa diandalkan.
3. Parameter yang benar-benar difilter server (`--probe`). Kalau tidak ada,
   mode otomatis harus membaca `meta.last_page` dan menyaring di sisi
   TransSurvey.
4. Mapping project ke `workspaces.code` di TransSurvey. Respons tiket tidak
   memuat project sama sekali, jadi mapping ini **belum bisa** dilakukan dari
   API tiket; perlu endpoint project atau kesepakatan satu workspace default.
5. Nama field sumber CSAT: `source`, `category_name`, `section_name`,
   `subject`.
6. Perilaku pagination: pakai `page` + `meta.last_page`, jangan `next_url`
   (contoh di PDF memakai `127.0.0.1`). `per_page` di production mengikuti
   nilai yang dikirim (diuji 20).

---

## 7. Batasan keamanan

- Command tidak menulis token/password ke output atau log.
- Command tidak menyimpan data customer ke database.
- `--show-pii` hanya untuk server; jangan dijalankan di CI atau screenshot.
- Kredensial hanya di `.env` server, bukan di repo.
