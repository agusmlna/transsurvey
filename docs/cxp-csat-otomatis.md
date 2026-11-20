# CSAT otomatis dari tiket TransCXP

Fase 5: command `cxp:csat-send` membaca tiket TransCXP yang **baru masuk status
Close**, lalu mengirim CSAT memakai template default workspace.

Fase 6b menambahkan **kanal WhatsApp** lewat API WhatsApp Broadcast TransCXP.
Kanal ditentukan dari kolom `source` tiket, dan email TransSurvey tetap dipakai
sebagai fallback. Detail ada di bagian 3 dan 7.

## 1. Cara kerja

```text
tiap jam (atau manual)
  login ke API TransCXP
  untuk tiap workspace (kode = id_project):
      GET /api/v1/dataTicketByStatus?status=Close&project=<kode>&per_page=100
      baca semua halaman
      bandingkan dengan snapshot cxp_ticket_seen
      tiket yang belum pernah terlihat:
          tentukan kanal dari source tiket
          WhatsApp -> API WhatsApp TransCXP (fallback email)
          Email    -> antrean mailer `csat` (SMTP kedua, bukan SMTP operasi)
      simpan snapshot tiket yang sudah ditangani
  email masuk antrean -> queue worker mengirim
  cxp:csat-wa-status memeriksa status pesan WhatsApp
```

Endpoint `/api/v1/dataTicketByStatus` adalah satu-satunya endpoint TransCXP
yang terbukti **menyaring status dan project di server**. Hasil probe production
12 Nov 2026:

| Query | total | Isi halaman |
| --- | --- | --- |
| `Close` + `project=22` | 132 | `Close` 100/100 |
| `Close` + `project=10` | 8 | `Close` 8/8 |
| `Close` + `project=21` | 8.460 | `Close` 100/100 |
| `Close` + `project=6` | 28 | `Close` 28/28 |
| `Close` tanpa project | 53.148 | `Close` 100/100 |
| `Open` + `project=22` | 91.377 | `Open` 100/100 |

Catatan server:

- `per_page` dibatasi **100**, walaupun nilai lebih besar dikirim;
- kecepatan ±0,45 detik per halaman;
- tidak ada duplikat antar halaman;
- project yang tidak ada menghasilkan `total=0`;
- pagination ada di kunci `pagination.last_page`.

Endpoint lama tidak dipakai lagi untuk CSAT otomatis:

- `/api/v1/dataTicketcxp` mengabaikan semua filter (±1,05 juta baris, ±10.464
  halaman);
- `/api/v2/tickets` tidak membawa `id_project` sehingga tidak aman untuk
  banyak project.

## 2. Snapshot dan baseline

Tabel `cxp_ticket_seen` menyimpan pasangan `project_code + ticket_id` yang
pernah terlihat berstatus Close.

| Kondisi | Perilaku |
| --- | --- |
| Project belum punya snapshot | Run pertama hanya **baseline**: simpan semua tiket Close, tidak mengirim |
| Project sudah punya snapshot | Kirim hanya tiket yang belum ada di snapshot |
| Tiket sudah punya undangan CSAT | Dilewati (unique `workspace + ticket_id`) |
| Tiket tidak punya email / email tidak valid | Dilewati, tetap dicatat di snapshot |
| Scan satu project gagal di tengah | Snapshot project itu tidak ditulis; tiket yang terlewat dianggap baru pada run berikutnya |
| Project gagal | Project lain tetap diproses |

Baseline mencegah ratusan tiket lama terkirim saat pertama kali dijalankan.
Tiket yang saat baseline masih `Open` dan nanti berubah menjadi `Close` **akan**
terkirim, karena belum ada di snapshot.

Tanpa `--send`, command hanya simulasi: tidak menulis snapshot, tidak membuat
undangan, dan tidak mengantrekan email. Karena itu simulasi pada project baru
selalu menampilkan baseline.

## 3. Routing kanal dan WhatsApp

Kanal dipilih dari kolom `source` tiket. Perbandingan tidak peka huruf
besar/kecil, spasi, garis bawah, atau tanda hubung (`Google_business` sama
dengan `Google Business`).

| Source | Perilaku |
| --- | --- |
| `WhatsApp` | Kirim WhatsApp bila nomor valid **dan** workspace punya akun WA; kalau tidak, fallback email; kalau email juga tidak ada, dilewati |
| `Email` | Email TransSurvey; tanpa email dilewati |
| `Instagram`, `Facebook`, `Google_business`, `Twitter`, `Livechat`, `TikTok` | Email bila ada; tanpa email dilewati (tidak memakai WhatsApp) |
| `Call`, `Outbound Call`, `WAG`, `Whatsapp Call`, `Whatsapp Call Outbound` | Dilewati sepenuhnya |
| Source lain / kosong | Ikut `CXP_CSAT_DEFAULT_CHANNEL` (default `email`) |

Aturan penting:

- **Nomor pengirim berbeda per project.** Kredensial User API v2
  (email + password) per workspace disimpan di `.env` (`CXP_WA_ACCOUNTS`) atau
  di file server (`CXP_WA_ACCOUNTS_FILE`). Login memakai
  `/api/v2/login-user`; token-nya dipakai `/api/v2/messages-template`.
  Tidak ada akun fallback: project tanpa akun tidak akan mengirim WhatsApp
  supaya pesan tidak keluar dari nomor project lain.
- Satu project boleh punya banyak channel aktif. Daftar channel disimpan di
  `channels`; akun dengan `active: no` diabaikan. Kredensial bisa ditaruh di
  level project dan diwariskan ke semua channel. Karena endpoint v2 tidak punya
  pemilih channel, nomor yang keluar tetap ditentukan oleh akun login project
  tersebut.
- Satu template WhatsApp dipakai semua project. Nama project bisa muncul di
  dalam template lewat parameter body, jadi tidak perlu template per project.
- Tautan tetap satu per tiket. Jalur WhatsApp memakai aturan yang sama dengan
  email (`CsatInvitationService::prepareWhatsApp()` -> `issueLink()`).
- Status pesan disimpan di tabel `csat_whatsapp_messages` dengan dedupe
  `csat-wa:<invitation_id>`, sehingga satu undangan tidak pernah punya dua
  pesan WhatsApp.

### SMTP terpisah untuk CSAT

Email operasi (notifikasi admin, user, undangan non-CSAT) tetap memakai mailer
`smtp` dengan variabel `MAIL_*`. Email undangan CSAT memakai mailer `csat`
dengan variabel `MAIL_CSAT_*` dan identitas `From`-nya sendiri.

```env
MAIL_CSAT_SCHEME=smtp
MAIL_CSAT_HOST=
MAIL_CSAT_PORT=587
MAIL_CSAT_USERNAME=
MAIL_CSAT_PASSWORD=
MAIL_CSAT_FROM_ADDRESS=
MAIL_CSAT_FROM_NAME="CSAT TransSurvey"
```

Aturan yang harus dijaga:

- `CsatInvitationMail` dipaksa memakai `mailer('csat')`. Jangan dihapus.
- Kalau `MAIL_CSAT_*` belum lengkap, `cxp:csat-send --send` dan queue worker
  **gagal dengan pesan yang jelas**. Tidak ada fallback ke SMTP operasi,
  supaya CSAT tidak pernah keluar dengan alamat email Operation.
- `MAIL_CSAT_FROM_ADDRESS` wajib diisi. Kalau kosong, CSAT dianggap belum siap
  walaupun host SMTP-nya sudah ada.
- Kredensial hanya ditulis di `.env` server. Jangan commit, dan jangan tempel
  di chat atau tiket.

Alur pengiriman WhatsApp:

```text
cxp:csat-send --send
  source WhatsApp + nomor valid + akun workspace tersedia
    -> prepareWhatsApp(): buat/ambil tautan, catat delivery status pending
    -> POST /api/v2/messages-template (login Basic Auth, token di-cache)
    -> sukses: delivery sent + invitation.sent_at/expires_at
    -> gagal sementara (koneksi/5xx/429): delivery failed, tiket TIDAK masuk
       snapshot sehingga dicoba lagi run berikutnya
    -> gagal permanen (4xx): delivery failed, lalu fallback email bila ada
```

Endpoint kirim yang dipakai adalah `POST /api/v2/messages-template` sesuai
dokumen WhatsApp Broadcast API v2. Dokumen yang sama juga memuat contoh curl
`/api/v2/messages-broadcast`; kalau TransCXP mengonfirmasi path lain, ganti
`CXP_WA_SEND_PATH` tanpa mengubah kode.

#### Sumber nomor pengirim per project

`whatsapp_number`, `id`, dan daftar akun aktif dari
`/api/v1/accountWaProject/{id_project}` bisa diisi otomatis ke
`CXP_WA_ACCOUNTS_FILE`:

```bash
php artisan cxp:csat-wa-accounts-sync --projects=23 --dry-run
php artisan cxp:csat-wa-accounts-sync --projects=23
```

Per project yang tersinkron: `number` <- `whatsapp_number` (diawali `+`) dan
`account_id` <- field `id`. Bila ada lebih dari satu akun aktif, semua akun
ditulis ke `channels` (berisi `account_id`, `number`, `name`, `active`). Akun
dengan `active != yes` dilewati. Username/password API v2 tidak pernah ditimpa;
kredensial bisa ditaruh di level project dan dipakai untuk semua channel.

Contoh untuk project dengan banyak channel (DKT):

```json
{
  "9": {
    "username": "dkt@trans-cosmos.co.id",
    "password": "<password asli di server>",
    "number": "+6281113900527",
    "account_id": "110",
    "channels": [
      {"account_id": "110", "number": "+6281113900527", "name": "Berani Berencana", "active": "yes"},
      {"account_id": "107", "number": "+6281119726459", "name": "DKT News", "active": "yes"},
      {"account_id": "106", "number": "+628111326459", "name": "Halo DKT", "active": "yes"}
    ]
  }
}
```

**Batasan penting:** `messages-template` tidak punya parameter pengirim, jadi
`channels` hanya metadata/audit. Nomor yang muncul di WA ditentukan oleh akun
User API yang dipakai login. Kalau satu project perlu mengirim dari beberapa
nomor berbeda, TransCXP harus menyediakan endpoint/field pemilih channel;
endpoint lama tidak diubah.

Status pesan (`sent` -> `delivered` -> `read`, atau `failed`) tidak punya
webhook, jadi diperiksa oleh command terjadwal:

```bash
php artisan cxp:csat-wa-status --limit=100
php artisan cxp:csat-wa-status --workspace=22
php artisan cxp:csat-wa-status --message=<message_id>
```

## 4. Mapping project ke workspace

Project TransCXP dikirim sebagai parameter `project`, diambil dari **kode
workspace**. Kode workspace **wajib sama** dengan `id_project` TransCXP
(mis. `6`, `10`, `21`, `22`).

| Situasi | Perilaku |
| --- | --- |
| Kode workspace = `id_project` | Tiket diproses untuk workspace itu |
| Project tidak punya workspace | Tidak pernah dipanggil, jadi tidak ada salah kirim |
| Workspace `legacy` | Dilewati |
| Workspace tanpa template CSAT default aktif | Dilewati dan dihitung di ringkasan |

Tidak ada fallback ke workspace default. Kalau kode workspace masih berupa nama
(mis. `DIPOOPL`), endpoint akan mengembalikan `total=0`; perbaiki kodenya atau
batasi run dengan `CXP_CSAT_WORKSPACE`.

### Onboarding workspace production

Command `cxp:csat-onboard` menyiapkan workspace untuk daftar project TransCXP:
satu workspace per project, satu salinan template CSAT per workspace (supaya
laporan tidak tercampur), masa berlaku tautan, dan penanda autosend untuk
project pilot.

```bash
# Lihat rencana tanpa menulis database.
php artisan cxp:csat-onboard --dry-run

# Semua project dari config/cxp.php, clone dari template CSAT legacy.
php artisan cxp:csat-onboard --from=12 --expiry-days=7

# Hanya pilot: pakai CXP_CSAT_PILOT_PROJECTS dan aktifkan autosend untuk kode itu.
php artisan cxp:csat-onboard --pilot

# Semua project, tetapi autosend hanya untuk kode di CXP_CSAT_PILOT_PROJECTS.
php artisan cxp:csat-onboard --projects=4,5,6,8 --pilot
```

| Opsi | Arti |
| --- | --- |
| `--projects=4,5` | Batasi ke kode project tertentu (default semua dari config) |
| `--from=<id>` | ID template CSAT sumber (default `CXP_CSAT_MASTER_TEMPLATE_ID` atau template legacy) |
| `--template=` | Judul template baru; `:name` dan `:code` diganti otomatis |
| `--expiry-days=7` | Masa berlaku tautan CSAT |
| `--pilot` | Aktifkan `settings.csat.autosend` hanya untuk kode pilot |
| `--activate` | Jadikan template hasil clone sebagai default |
| `--force` | Clone ulang dan jadikan default walaupun workspace sudah punya |
| `--sync-names` | Samakan nama workspace dengan daftar project |
| `--dry-run` | Tampilkan rencana tanpa menulis database |

Workspace baru otomatis mendapat bank pertanyaan default. Template lama tidak
dihapus saat `--force`; hanya default workspace yang berpindah ke template baru.

## 5. Konfigurasi

Isi di `.env` server (jangan di repo):

```dotenv
# Jadwal otomatis tiap jam. false = hanya bisa dijalankan manual.
CXP_CSAT_AUTOSEND=false

# Opsional. Kosong = semua workspace non-legacy yang punya template default.
# Isi kode workspace untuk membatasi satu project saat uji.
CXP_CSAT_WORKSPACE=

# Server membatasi per_page di 100.
CXP_CSAT_PER_PAGE=100

# 0 = baca semua halaman per project.
CXP_CSAT_MAX_PAGES=0

# Routing kanal (Fase 6b). Pisahkan dengan koma.
CXP_CSAT_WA_SOURCES=WhatsApp
CXP_CSAT_EMAIL_SOURCES=Email
CXP_CSAT_EMAIL_FALLBACK_SOURCES="Instagram,Facebook,Google_business,Twitter,Livechat,TikTok"
CXP_CSAT_SKIP_SOURCES="Call,Outbound Call,WAG,Whatsapp Call,Whatsapp Call Outbound"
CXP_CSAT_DEFAULT_CHANNEL=email

# WhatsApp Broadcast API v2 (production).
CXP_WA_BASE_URL=https://transcxp.trans-cosmos.id
CXP_WA_TIMEOUT=30
CXP_WA_TEMPLATE_ID=
CXP_WA_TEMPLATE_NAME=
CXP_WA_TEMPLATE_LANGUAGE=id
# Urutan parameter body template, contoh: customer_name,survey_url,project_name
CXP_WA_TEMPLATE_PARAMS=customer_name,survey_url
# Opsional bila template memakai tombol URL dinamis, contoh: survey_url
CXP_WA_BUTTON_URL_PARAM=
# JSON kredensial per kode workspace (nomor +628...).
CXP_WA_ACCOUNTS=
CXP_WA_ACCOUNTS_FILE=
CXP_WA_TOKEN_CACHE_TTL=43200

# Endpoint filter status + project.
CXP_API_STATUS_PATH=/api/v1/dataTicketByStatus
```

`CXP_WA_ACCOUNTS` berisi JSON objek dengan kode workspace sebagai key. Format
singkat satu akun:

```json
{"22":{"username":"indofood@trans-cosmos.co.id","password":"...","number":"+628891122888"}}
```

Format dengan banyak channel aktif ada di bagian "Sumber nomor pengirim per
project". Kalau JSON lebih nyaman disimpan sebagai file, isi
`CXP_WA_ACCOUNTS_FILE` dengan path file di server. Hanya salah satu yang perlu
diisi. Password/token tidak pernah ditulis ke log.

`CXP_API_TICKET_PATH` dan `CXP_API_DETAIL_PATH` tidak dipakai command CSAT;
keduanya dipertahankan untuk `cxp:inspect` dan probe.

Workspace wajib punya **template CSAT aktif** yang dijadikan default
(menu CSAT → Template).

Setelah mengubah `.env`:

```bash
php artisan optimize:clear
```

Jangan lupa permission `.env` staging tetap `640 itsol:www-data`.

## 6. Menjalankan

Simulasi dulu (aman, tidak menulis apa pun):

```bash
php artisan cxp:csat-send
```

Periksa ringkasan: berapa project dibaca, baseline, tiket tanpa email, sudah
pernah dapat CSAT, dan berapa yang **akan** diantrekan.

Kirim sungguhan:

```bash
php artisan cxp:csat-send --send
```

Uji satu tiket pada satu project:

```bash
php artisan cxp:csat-send --send --workspace=6 --ticket=001027
```

`--ticket` melewati snapshot supaya tiket itu tetap diproses walaupun sudah
tercatat. Opsi ini wajib disertai `--workspace` (atau `CXP_CSAT_WORKSPACE`)
agar tidak memindai semua project. Run pertama dengan `--ticket` tetap
menyimpan baseline **penuh** project tersebut, agar sisa tiket tidak dianggap
baru pada run berikutnya.

Membatasi volume:

```bash
php artisan cxp:csat-send --send --limit=5
php artisan cxp:csat-send --send --max-pages=1
```

| Opsi | Arti |
| --- | --- |
| `--send` | Kirim sungguhan (email/WhatsApp); tanpa ini hanya simulasi |
| `--workspace=<kode>` | Batasi ke satu workspace/project |
| `--ticket=<id>` | Proses satu tiket; melewati snapshot |
| `--max-pages=0` | Batas halaman per project (0 = semua) |
| `--limit=0` | Batas pesan yang dikirim/diantrekan (0 = semua) |
| `--only-autosend` | Hanya workspace dengan `settings.csat.autosend = true` |
| `--all-workspaces` | Abaikan filter autosend per workspace |

Run manual (tanpa `--only-autosend`) tetap memproses semua workspace, termasuk
yang autosend-nya mati. Filter per workspace hanya berlaku saat
`CXP_CSAT_AUTOSEND=true` (jadwal) atau saat `--only-autosend` dipakai.

## 7. Jadwal otomatis

`routes/console.php` mendaftarkan:

```php
Schedule::command('cxp:csat-send --send')->hourly()->withoutOverlapping()
    ->when(fn () => (bool) config('cxp.csat.enabled'));
Schedule::command('cxp:csat-wa-status --limit=100')->everyFifteenMinutes()->withoutOverlapping()
    ->when(fn () => (bool) config('cxp.csat.enabled'));
```

Aktifkan setelah uji manual sukses:

```dotenv
CXP_CSAT_AUTOSEND=true
```

Saat jadwal aktif, hanya workspace dengan `settings.csat.autosend = true`
(menu CSAT -> Pengaturan) yang diproses. Ini memungkinkan pilot 2-3 project
lebih dulu tanpa mematikan project lain secara manual.

Pastikan scheduler server berjalan (cron `php artisan schedule:run` tiap menit)
dan queue worker aktif. `withoutOverlapping` mencegah dua proses berjalan
bersamaan.

## 8. Beban ke TransCXP

Beban = jumlah halaman per project. Contoh untuk project yang punya workspace:

| Project | Tiket Close | Halaman |
| --- | --- | --- |
| 22 | 132 | 2 |
| 10 | 8 | 1 |
| 21 | 8.460 | 85 |
| 6 | 28 | 1 |
| **Total** | | **89 request ≈ 40 detik** |

Project tanpa workspace tidak dipanggil. Kalau `CXP_CSAT_WORKSPACE` diisi,
hanya project itu yang dipanggil.

## 9. Idempotensi dan kegagalan

- Satu `ticket_id` hanya punya satu undangan CSAT per workspace. Run berikutnya
  melewati tiket itu bila sudah `sent_at`, `completed_at`, punya email aktif,
  atau punya pesan WhatsApp yang sudah diterima (sent/delivered/read).
- Delivery WhatsApp yang `failed` tidak dihitung selesai: tiket dicoba lagi pada
  run berikutnya selama gangguan bersifat sementara. Dedupe
  `csat-wa:<invitation_id>` mencegah baris ganda.
- Snapshot mencegah tiket yang sama diproses ulang tiap jam.
- Kegagalan sementara (koneksi/API error/5xx/429) tidak dicatat ke snapshot,
  sehingga dicoba lagi pada run berikutnya.
- Penolakan permanen WhatsApp (4xx) dicatat ke snapshot dan dianggap selesai
  (fallback email bila ada) agar tidak dicoba berulang tiap jam.
- Data yang memang tidak valid (tanpa email untuk source Email, tanpa nomor dan
  tanpa email untuk source WhatsApp) dicatat ke snapshot agar tidak dihitung
  ulang terus-menerus.
- Error satu project tidak menghentikan project lain.
- Email yang gagal dikirim tetap tercatat di `email_deliveries` dan bisa
  di-retry lewat mekanisme pengiriman yang sudah ada.
- Ringkasan setiap run dicatat di log (`cxp.csat-send`) tanpa email/nomor
  customer.

## 10. Batasan

- Tidak ada backfill tiket lama: run pertama per project adalah baseline.
- WhatsApp hanya mengirim **template yang sudah disetujui Meta**. Isi pesan
  tidak bisa diubah bebas dari kode; ubah template di TransCXP lalu sesuaikan
  `CXP_WA_TEMPLATE_ID`/`CXP_WA_TEMPLATE_PARAMS`.
- Tidak ada webhook status WhatsApp; status diambil lewat `cxp:csat-wa-status`.
- Nomor pengirim mengikuti akun User API project masing-masing. Tidak ada
  akun/nomor fallback lintas project. Channel `active: no` tidak dipakai.
- Untuk project dengan beberapa channel aktif, daftar `channels` tersimpan sebagai
  metadata. Memilih nomor antar-channel membutuhkan endpoint khusus TransCXP.
- Kode workspace harus sama dengan `id_project` TransCXP.
- Kalau tiket berubah dari Close → Open → Close lagi, tiket itu tidak dikirim
  ulang karena sudah ada di snapshot. Ini disengaja (satu tiket satu CSAT).
- Schedule baru aman dinyalakan setelah staging terbukti dan kode workspace
  production sudah diverifikasi.
