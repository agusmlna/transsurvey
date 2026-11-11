# CSAT otomatis dari tiket TransCXP

Fase 5: command `cxp:csat-send` membaca tiket TransCXP yang **sudah ditutup**,
lalu mengantrekan email CSAT memakai template default workspace. Tidak ada
pengiriman WhatsApp pada fase ini; nomor WA hanya disimpan di `clients.wa`.

## 1. Cara kerja

1. Login ke API TransCXP memakai kredensial `CXP_API_*`.
2. Ambil daftar tiket dari endpoint yang diatur `CXP_API_TICKET_PATH`,
   halaman per halaman sampai `last_page`:
   - `/api/v1/dataTicketcxp` (disarankan): pagination di kunci `pagination`,
     dan setiap baris memuat `id_project`/`project_name`;
   - `/api/v2/tickets` (default lama): pagination di kunci `meta`, tanpa field
     project.
3. Untuk tiap tiket:
   - hanya status `Close` yang diproses;
   - `update_date` harus `>= CXP_CSAT_SINCE`;
   - wajib ada email customer yang valid (kalau tidak, dilewati);
   - kalau respons membawa `id_project`, tiket masuk ke workspace yang
     kodenya sama dengan `id_project` (lihat bagian 4);
   - tiket yang sudah punya undangan CSAT di workspace tujuan dilewati;
   - nomor WA diambil dari `customer.wa` → `customer.phone_no` →
     `customer.mobile_phone` (atau field root `wa`/`phone_no`/`mobile_phone`
     pada kontrak v1), dinormalisasi ke `62...`, dan hanya disimpan kalau
     valid.
4. Undangan dibuat lewat `CsatInvitationService` yang sama dengan halaman
   Kirim CSAT manual, lalu email masuk antrean `email_deliveries`.

Command menerima dua bentuk respons: v2 (`customer.email`,
`status_name`, `source`, pagination `meta`) dan v1 (`email`, `status_ticket`,
`name_customer`, `wa` di root, pagination `pagination`). Perpindahan endpoint
cukup lewat `CXP_API_TICKET_PATH`; tidak perlu ubah kode.

Tanpa opsi `--send`, command hanya **simulasi**: tidak ada baris database yang
ditulis dan tidak ada email yang diantrekan.

## 2. Kenapa memakai `update_date`, bukan `end_date`

Hasil probe production (11 Nov 2026):

| Parameter | Hasil |
| --- | --- |
| `status=Close` | didukung (464 tiket) |
| `start_date` | didukung, menyaring tanggal **dibuat** |
| `end_date` | diabaikan |
| `update_date`, `updated_at`, `last_update` | diabaikan |
| `order_by`, `sort_by` | diabaikan |
| `id_project`, `search` | diabaikan |
| `per_page` > 20 | diabaikan (tetap 20) |

`end_date` selalu `null`, termasuk untuk tiket `Close`, dan tidak ada filter
tanggal tutup di server. Karena itu:

- Command membaca **seluruh** halaman `status=Close` (sekitar 24 halaman untuk
  464 tiket). Ini sekitar 24 request per jam, masih ringan.
- Waktu tutup didekati dengan `update_date`, lalu dibandingkan dengan
  `CXP_CSAT_SINCE`. Tiket lama yang baru ditutup tetap ikut terkirim, sedangkan
  tiket lama yang sudah ditutup sebelum go-live dilewati.

Kalau nanti TransCXP menambahkan filter `update_date` atau sort by update,
command bisa dipersempit tanpa mengubah aturan bisnis.

Catatan status endpoint (11 Nov 2026): `/api/v1/dataTicketcxp` sudah membalas
**200** dengan envelope `status/code/data/pagination` dan memuat `id_project`,
`project_name`, serta `wa`. `/api/v1/detailChoose` masih **404**, tetapi tidak
dipakai oleh command. `/api/v2/tickets` tetap jalan namun tanpa field project.

## 3. Konfigurasi

Isi di `.env` server (jangan di repo):

```dotenv
# Jadwal otomatis tiap jam. false = hanya bisa dijalankan manual.
CXP_CSAT_AUTOSEND=false

# Kode workspace penerima CSAT (bukan nama).
CXP_CSAT_WORKSPACE=

# Tiket dengan update_date sebelum tanggal ini dilewati.
# Isi dengan tanggal go-live, mis. 2026-11-11.
CXP_CSAT_SINCE=2026-11-11

# 0 = baca semua halaman status Close.
CXP_CSAT_MAX_PAGES=0

# Endpoint daftar tiket. Pakai v1 kalau production melayani banyak project,
# karena hanya v1 yang mengirim id_project/project_name.
CXP_API_TICKET_PATH=/api/v1/dataTicketcxp
```

`CXP_API_DETAIL_PATH` tidak perlu diubah; detail tiket belum dipakai command.

Workspace wajib punya **template CSAT aktif** yang dijadikan default
(menu CSAT → Template). Workspace `legacy` ditolak.

`CXP_CSAT_WORKSPACE` adalah workspace **default**: dipakai kalau tiket tidak
membawa `id_project`, atau kalau `id_project` sama dengan kode workspace ini.
Kalau tiket membawa `id_project` lain, command mencari workspace dengan kode
yang sama (bagian 4).

Setelah mengubah `.env`:

```bash
php artisan optimize:clear
```

Jangan lupa permission `.env` staging tetap `640 itsol:www-data`.

## 4. Mapping project ke workspace

User Guide API TransCXP v1.0 (10 halaman) mencantumkan field `id_project` dan
`project_name` pada `GET /api/v1/dataTicketcxp`. Endpoint itu sudah aktif
(200), sehingga mapping project berjalan otomatis kalau
`CXP_API_TICKET_PATH=/api/v1/dataTicketcxp`. `/api/v2/tickets` tidak memuat
field project, jadi hanya aman untuk satu project.

| Respons tiket | Perilaku |
| --- | --- |
| ada `id_project` | Cari workspace dengan `lower(code) = lower(id_project)` |
| `id_project` tidak cocok | Coba `project_name` ke nama workspace, hanya kalau hasilnya unik |
| tidak ada `id_project` | Pakai workspace `CXP_CSAT_WORKSPACE` |

Aturan penting:

- `id_project` di User Guide berupa angka (contoh `"6"`), sedangkan
  `project_name` berupa nama (contoh `"DIPOOPL"`). Karena SSO memakai
  `project.code` sebagai kode workspace, cara paling akurat adalah membuat
  kode workspace sama dengan `project.code`/`id_project` (mis. `6`). Kalau
  tidak, command mencoba mencocokkan `project_name` dengan **nama** workspace
  secara unik.
- Project yang tidak punya workspace, atau workspace-nya `legacy`, **dilewati**
  dan dihitung di baris `Project tanpa workspace`. Tidak ada fallback diam-diam
  ke workspace default supaya data customer tidak mendarat di tempat salah.
- Workspace tujuan wajib punya template CSAT default aktif. Kalau tidak,
  tiket dihitung di baris `Workspace tanpa template aktif`.
- Dedupe memakai pasangan `workspace + ticket_id`, karena `id_ticket` tidak
  unik antar project.

Sebelum mengaktifkan `CXP_CSAT_AUTOSEND`, pastikan kode workspace sudah cocok
dengan `id_project` di TransCXP. Kalau `CXP_API_TICKET_PATH` masih v2, semua
tiket masuk ke workspace default dan baris `Project tanpa workspace` akan
selalu 0.

## 5. Menjalankan

Simulasi dulu (aman, tidak menulis apa pun):

```bash
php artisan cxp:csat-send
```

Periksa tabel ringkasan: berapa tiket dibaca, tanpa email, sudah pernah
dikirim, dan berapa yang **akan** diantrekan.

Kalau angkanya wajar, kirim sungguhan:

```bash
php artisan cxp:csat-send --send
```

Uji satu tiket:

```bash
php artisan cxp:csat-send --send --ticket=001060
```

Batasi jumlah email per run:

```bash
php artisan cxp:csat-send --send --limit=5
```

Opsi lain:

| Opsi | Arti |
| --- | --- |
| `--since=2026-11-11` | Mengganti `CXP_CSAT_SINCE` untuk run ini |
| `--workspace=brand-a` | Mengganti `CXP_CSAT_WORKSPACE` |
| `--page=1` | Halaman awal |
| `--max-pages=0` | Batas halaman yang dibaca |
| `--limit=0` | Batas email yang diantrekan |

## 6. Jadwal otomatis

`routes/console.php` mendaftarkan:

```php
Schedule::command('cxp:csat-send --send')->hourly()->withoutOverlapping()
    ->when(fn () => (bool) config('cxp.csat.enabled'));
```

Aktifkan setelah uji manual sukses:

```dotenv
CXP_CSAT_AUTOSEND=true
```

Lalu pastikan scheduler server berjalan (cron `php artisan schedule:run` tiap
menit). `withoutOverlapping` mencegah dua proses berjalan bersamaan.

## 7. Idempotensi dan kegagalan

- Satu `ticket_id` hanya punya satu undangan CSAT per workspace. Run berikutnya
  melewati tiket itu walaupun email sebelumnya masih `pending`.
- Kalau run berhenti di tengah, jalankan ulang: tiket yang sudah dibuatkan
  undangan otomatis dilewati, sisanya dilanjutkan.
- Email yang gagal dikirim tetap tercatat di `email_deliveries` dan bisa
  di-retry lewat mekanisme pengiriman yang sudah ada.
- Ringkasan setiap run dicatat di log (`cxp.csat-send`) tanpa email/nomor
  customer.

## 8. Batasan

- Tidak ada backfill tiket lama: `CXP_CSAT_SINCE` mencegah email massal saat
  pertama kali dijalankan.
- Mapping project aktif kalau endpoint tiket mengirim `id_project`
  (`CXP_API_TICKET_PATH=/api/v1/dataTicketcxp`). Dengan v2, semua tiket masuk
  ke satu workspace default.
- Tidak ada pengiriman WhatsApp; hanya penyimpanan nomor.
- 17 dari 20 tiket terbaru tidak punya email, jadi volume CSAT otomatis
  cenderung kecil. Itu diharapkan.
- `/api/v1/dataTicketcxp` sudah aktif, tetapi `/api/v1/detailChoose` masih 404.
  Command tidak memakai detailChoose, jadi tidak menghalangi.
- v1 tidak mendukung filter `status`; command membaca semua halaman lalu
  menyaring `Close` di sisi aplikasi.
