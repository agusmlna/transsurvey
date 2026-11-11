# CSAT otomatis dari tiket TransCXP

Fase 5: command `cxp:csat-send` membaca tiket TransCXP yang **sudah ditutup**,
lalu mengantrekan email CSAT memakai template default workspace. Tidak ada
pengiriman WhatsApp pada fase ini; nomor WA hanya disimpan di `clients.wa`.

## 1. Cara kerja

1. Login ke API TransCXP memakai kredensial `CXP_API_*`.
2. Ambil `GET /api/v2/tickets?status=Close`, halaman per halaman sampai
   `meta.last_page`.
3. Untuk tiap tiket:
   - hanya status `Close` yang diproses;
   - `update_date` harus `>= CXP_CSAT_SINCE`;
   - wajib ada `customer.email` yang valid (kalau tidak, dilewati);
   - tiket yang sudah punya undangan CSAT di workspace dilewati;
   - nomor WA diambil dari `customer.wa` → `customer.phone_no` →
     `customer.mobile_phone`, dinormalisasi ke `62...`, dan hanya disimpan
     kalau valid.
4. Undangan dibuat lewat `CsatInvitationService` yang sama dengan halaman
   Kirim CSAT manual, lalu email masuk antrean `email_deliveries`.

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
```

Workspace wajib punya **template CSAT aktif** yang dijadikan default
(menu CSAT → Template). Workspace `legacy` ditolak.

Karena respons `/api/v2/tickets` tidak memuat project, semua tiket otomatis
masuk ke satu workspace ini. Mapping project menunggu field/endpoint dari
TransCXP.

Setelah mengubah `.env`:

```bash
php artisan optimize:clear
```

Jangan lupa permission `.env` staging tetap `640 itsol:www-data`.

## 4. Menjalankan

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

## 5. Jadwal otomatis

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

## 6. Idempotensi dan kegagalan

- Satu `ticket_id` hanya punya satu undangan CSAT per workspace. Run berikutnya
  melewati tiket itu walaupun email sebelumnya masih `pending`.
- Kalau run berhenti di tengah, jalankan ulang: tiket yang sudah dibuatkan
  undangan otomatis dilewati, sisanya dilanjutkan.
- Email yang gagal dikirim tetap tercatat di `email_deliveries` dan bisa
  di-retry lewat mekanisme pengiriman yang sudah ada.
- Ringkasan setiap run dicatat di log (`cxp.csat-send`) tanpa email/nomor
  customer.

## 7. Batasan

- Tidak ada backfill tiket lama: `CXP_CSAT_SINCE` mencegah email massal saat
  pertama kali dijalankan.
- Tidak ada mapping project → satu workspace default.
- Tidak ada pengiriman WhatsApp; hanya penyimpanan nomor.
- 17 dari 20 tiket terbaru tidak punya email, jadi volume CSAT otomatis
  cenderung kecil. Itu diharapkan.
