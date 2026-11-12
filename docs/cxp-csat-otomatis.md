# CSAT otomatis dari tiket TransCXP

Fase 5: command `cxp:csat-send` membaca tiket TransCXP yang **baru masuk status
Close**, lalu mengantrekan email CSAT memakai template default workspace.
Tidak ada pengiriman WhatsApp pada fase ini; nomor WA hanya disimpan di
`clients.wa`.

## 1. Cara kerja

```text
tiap jam (atau manual)
  login ke API TransCXP
  untuk tiap workspace (kode = id_project):
      GET /api/v1/dataTicketByStatus?status=Close&project=<kode>&per_page=100
      baca semua halaman
      bandingkan dengan snapshot cxp_ticket_seen
      tiket yang belum pernah terlihat → kirim CSAT
      simpan snapshot
  email masuk antrean → queue worker mengirim
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

## 3. Mapping project ke workspace

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

## 4. Konfigurasi

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

# Endpoint filter status + project.
CXP_API_STATUS_PATH=/api/v1/dataTicketByStatus
```

`CXP_API_TICKET_PATH` dan `CXP_API_DETAIL_PATH` tidak dipakai command CSAT;
keduanya dipertahankan untuk `cxp:inspect` dan probe.

Workspace wajib punya **template CSAT aktif** yang dijadikan default
(menu CSAT → Template).

Setelah mengubah `.env`:

```bash
php artisan optimize:clear
```

Jangan lupa permission `.env` staging tetap `640 itsol:www-data`.

## 5. Menjalankan

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
| `--send` | Antre email sungguhan; tanpa ini hanya simulasi |
| `--workspace=<kode>` | Batasi ke satu workspace/project |
| `--ticket=<id>` | Proses satu tiket; melewati snapshot |
| `--max-pages=0` | Batas halaman per project (0 = semua) |
| `--limit=0` | Batas email yang diantrekan (0 = semua) |

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

Pastikan scheduler server berjalan (cron `php artisan schedule:run` tiap menit)
dan queue worker aktif. `withoutOverlapping` mencegah dua proses berjalan
bersamaan.

## 7. Beban ke TransCXP

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

## 8. Idempotensi dan kegagalan

- Satu `ticket_id` hanya punya satu undangan CSAT per workspace. Run berikutnya
  melewati tiket itu walaupun email sebelumnya masih `pending`.
- Snapshot mencegah tiket yang sama diproses ulang tiap jam.
- Kegagalan sementara (mis. koneksi/API error) tidak dicatat ke snapshot,
  sehingga dicoba lagi pada run berikutnya.
- Data yang memang tidak valid (email tidak ada/tidak valid) dicatat ke
  snapshot agar tidak dihitung ulang terus-menerus.
- Error satu project tidak menghentikan project lain.
- Email yang gagal dikirim tetap tercatat di `email_deliveries` dan bisa
  di-retry lewat mekanisme pengiriman yang sudah ada.
- Ringkasan setiap run dicatat di log (`cxp.csat-send`) tanpa email/nomor
  customer.

## 9. Batasan

- Tidak ada backfill tiket lama: run pertama per project adalah baseline.
- Tidak ada pengiriman WhatsApp; hanya penyimpanan nomor.
- Kode workspace harus sama dengan `id_project` TransCXP.
- Kalau tiket berubah dari Close → Open → Close lagi, tiket itu tidak dikirim
  ulang karena sudah ada di snapshot. Ini disengaja (satu tiket satu CSAT).
- Schedule baru aman dinyalakan setelah staging terbukti dan kode workspace
  production sudah diverifikasi.
