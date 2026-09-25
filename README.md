# Transsurvey — Client Satisfaction Survey

Aplikasi survei kepuasan klien untuk membuat kuesioner, membagikan tautan pengisian, memantau hasil, dan mengelola tindak lanjut. Dibangun dengan Laravel, Blade, PHP, dan database MySQL atau SQLite.

Panduan ini ditujukan untuk anggota tim yang mengunduh proyek dari GitHub dan menjalankannya di komputer masing-masing. Nama aplikasi adalah **Transsurvey**; beberapa nama teknis lama masih menggunakan `suara`, termasuk perintah `php artisan suara:user`. Gunakan nama perintah tersebut apa adanya.

> Source code dan seeder dibagikan melalui GitHub. Database lokal, akun login, dan konfigurasi rahasia tidak otomatis ikut saat clone. Setiap anggota tim membuat database dan akun sendiri, kemudian menjalankan seeder untuk mendapatkan data contoh bawaan.

## Daftar isi

- [Fitur](#fitur)
- [Kebutuhan perangkat](#kebutuhan-perangkat)
- [Setup pertama: SQLite](#setup-pertama-sqlite)
- [Alternatif: MySQL](#alternatif-mysql)
- [Data contoh yang tersedia](#data-contoh-yang-tersedia)
- [Cara mencoba sistem](#cara-mencoba-sistem)
- [Akun dan hak akses](#akun-dan-hak-akses)
- [Email dan reminder](#email-dan-reminder)
- [Menjalankan kembali dan mengambil update](#menjalankan-kembali-dan-mengambil-update)
- [Struktur proyek dan tampilan](#struktur-proyek-dan-tampilan)
- [Membagikan data tambahan](#membagikan-data-tambahan)
- [Upload pertama ke GitHub](#upload-pertama-ke-github)
- [Kolaborasi tim](#kolaborasi-tim)
- [Pengujian](#pengujian)
- [Troubleshooting](#troubleshooting)
- [Deployment dan referensi](#deployment-dan-referensi)

## Fitur

| Menu | Kegunaan |
| --- | --- |
| Dashboard | Ringkasan kepuasan, response rate, jumlah respons, survei aktif, kategori, dan tren |
| Kuesioner | Pertanyaan rating 1–5, pilihan tunggal, teks, kategori, urutan, wajib/opsional, template, duplikasi, dan pratinjau |
| Klien | Perusahaan, PIC, email, proyek, dan status aktif |
| Distribusi | Tautan unik per klien/survei, status pengisian, dan pengiriman email setelah SMTP dikonfigurasi |
| Respons | Rincian jawaban dan komentar |
| Bank Pertanyaan | Pertanyaan yang dapat digunakan kembali |
| Laporan | Filter, ekspor CSV untuk Excel, dan cetak/simpan PDF melalui browser |
| Tindak Lanjut | Tindak lanjut otomatis untuk rating 1–3, penanggung jawab, tenggat, status, dan catatan |

Responden membuka tautan survei tanpa akun. Mereka dapat menyimpan draf dan mengirim satu respons final untuk setiap undangan.

## Kebutuhan perangkat

| Komponen | Kebutuhan |
| --- | --- |
| PHP | 8.3 atau lebih baru, dengan versi yang kompatibel dengan `composer.lock` |
| Framework | Laravel 13; versi dependency mengikuti `composer.lock` |
| Composer | Composer 2 |
| Git | Untuk clone, commit, pull, dan push |
| Database | SQLite untuk setup lokal cepat, atau MySQL |
| Browser | Browser modern |
| Node.js/npm | Tidak diperlukan untuk source bawaan; CSS/JS dilayani langsung dari `public/` |

Periksa di terminal:

```sh
php -v
php --ini
composer --version
git --version
php -m
```

Extension PHP mencakup `curl`, `mbstring`, `openssl`, `fileinfo`, `PDO`, `dom`, `xml`, `xmlwriter`, serta extension standar Laravel. Untuk SQLite aktifkan `pdo_sqlite` dan `sqlite3`; untuk MySQL aktifkan `pdo_mysql`. Perintah `composer check-platform-reqs` setelah instalasi akan memeriksa kebutuhan dependency yang benar-benar terpasang.

PHP pada XAMPP lama mungkin belum memenuhi versi proyek. Pastikan perintah `php` di terminal mengarah ke PHP yang benar. Di Windows gunakan `where.exe php` untuk memeriksanya.

## Setup pertama: SQLite

Pilih jalur ini bila ingin langsung mencoba aplikasi tanpa menyiapkan server MySQL. Jalankan perintah satu per satu; jika ada error, selesaikan error sebelum melanjutkan.

### 1. Clone repository

Terima undangan collaborator terlebih dahulu bila repository private. Ganti `USERNAME-ATAU-ORG` dan nama repository sesuai proyek tim:

```sh
git clone https://github.com/USERNAME-ATAU-ORG/transsurvey.git
cd transsurvey
composer install
composer check-platform-reqs
```

Gunakan `composer install` agar dependency mengikuti lock file yang sama dengan tim. Jangan menggunakan `composer update` untuk setup rutin.

### 2. Buat konfigurasi lokal

Pada clone baru yang belum memiliki `.env`:

```sh
php scripts/setup-local.php
```

Script membuat `.env`, file `database/database.sqlite`, dan folder runtime yang diperlukan. Path SQLite diatur otomatis sesuai komputer masing-masing. Script berhenti jika `.env` sudah ada agar tidak menimpa konfigurasi.

Buka `.env` dan pastikan nilai berikut:

```dotenv
APP_NAME=Transsurvey
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000
APP_TIMEZONE=Asia/Jakarta
MAIL_FROM_NAME="${APP_NAME}"
SURVEY_EMAIL_ENABLED=false
```

Pertahankan `DB_CONNECTION=sqlite` dan `DB_DATABASE` yang dibuat script. Gunakan host yang sama secara konsisten ketika membuka aplikasi, misalnya `127.0.0.1`.

### 3. Siapkan key, tabel, dan akun

```sh
php artisan key:generate
php artisan config:clear
php artisan migrate --seed
php artisan suara:user --role=admin
```

Isi nama, email, password, dan konfirmasi password saat diminta. Password minimal 12 karakter, mencakup huruf besar, huruf kecil, angka, dan simbol. Karakter password tidak ditampilkan saat diketik; ini normal.

**Tidak ada email atau password admin bawaan.** Gunakan akun yang baru dibuat untuk login. Akun GitHub collaborator berbeda dengan akun login Transsurvey.

`key:generate` hanya untuk instalasi baru dengan database baru. Jangan mengganti `APP_KEY` pada database yang sudah digunakan karena token undangan tersimpan terenkripsi.

### 4. Isi data contoh dan jalankan aplikasi

Admin harus sudah dibuat sebelum menjalankan `DemoSeeder`:

```sh
php artisan db:seed --class=DemoSeeder
php artisan serve
```

Buka **http://127.0.0.1:8000**, lalu login menggunakan akun sendiri. Biarkan terminal server tetap terbuka. Tekan `Ctrl+C` untuk menghentikannya.

Untuk mencoba menu, mengisi survei, dan melihat laporan lokal, worker email tidak perlu dijalankan.

## Alternatif: MySQL

Pilih jalur ini sebagai pengganti setup SQLite. Membuat atau mengganti database tidak otomatis memindahkan data dari database sebelumnya.

1. Clone proyek dan jalankan `composer install` serta `composer check-platform-reqs` seperti di atas.
2. Nyalakan MySQL, lalu buat database kosong khusus aplikasi, misalnya `transsurvey`, melalui phpMyAdmin atau alat database tim. Gunakan akun database dengan izin membuat tabel/migration pada database tersebut.
3. Pada clone baru yang belum memiliki `.env`, salin template.

Windows PowerShell:

```powershell
Copy-Item .env.example .env
```

macOS/Linux/Git Bash:

```sh
cp .env.example .env
```

4. Sesuaikan `.env`:

```dotenv
APP_NAME=Transsurvey
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000
APP_TIMEZONE=Asia/Jakarta
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=transsurvey
DB_USERNAME=transsurvey_user
DB_PASSWORD="GANTI_DENGAN_PASSWORD_DATABASE_LOKAL"
MAIL_FROM_NAME="${APP_NAME}"
SURVEY_EMAIL_ENABLED=false
```

`DB_USERNAME` dan `DB_PASSWORD` harus sesuai akun MySQL yang benar-benar tersedia; aplikasi tidak membuat akun MySQL tersebut.

5. Untuk database baru jalankan:

```sh
php artisan key:generate
php artisan config:clear
php artisan migrate --seed
php artisan suara:user --role=admin
php artisan db:seed --class=DemoSeeder
php artisan serve
```

## Data contoh yang tersedia

Data contoh didefinisikan dalam source code, sehingga tidak memerlukan file SQL atau salinan database pemilik proyek.

| Seeder | Isi |
| --- | --- |
| `database/seeders/DatabaseSeeder.php` | 6 bank pertanyaan: 4 rating, 1 pilihan kanal komunikasi, 1 saran teks |
| `database/seeders/DemoSeeder.php` | 1 survei aktif sekaligus template dengan 6 pertanyaan, 6 klien, 6 undangan, 4 respons selesai, 1 draf, 1 undangan belum diisi, dan 2 tindak lanjut |

Survei bernama **Contoh — Survei Kepuasan Klien**. Semua nama klien memiliki akhiran `(Contoh)`.

| Klien | Proyek | Status awal pengisian | Skor final | Tindak lanjut |
| --- | --- | --- | --- | --- |
| Nusantara Digital | Customer Care | Selesai | 4,50 / 5 | Tidak ada |
| Cakrawala Retail | Back Office | Selesai | 3,75 / 5 | Sedang diproses |
| Arunika Finance | IT Support | Selesai | 3,00 / 5 | Sedang diproses |
| Bumi Logistik | Customer Care | Selesai | 4,75 / 5 | Tidak ada |
| Sagara Media | Back Office | Draf; rating pertama terisi 4 | Belum dihitung | Tidak ada |
| Lentera Travel | IT Support | Belum mengisi | Belum dihitung | Tidak ada |

PIC menggunakan nama `PIC Contoh 1` sampai `PIC Contoh 6`. Email menggunakan `contoh1@example.invalid` sampai `contoh6@example.invalid`. Alamat ini adalah data dummy dan pengiriman email untuk data demo diblokir oleh aplikasi.

Pada database baru, tanpa filter periode/klien/proyek, hasil awal yang diharapkan:

- Rata-rata kepuasan **4,00 / 5**, setara **80%**.
- Response rate **67%**: 4 respons final dari 6 undangan.
- **1 survei aktif**, **4 respons final**, dan **2 tindak lanjut belum selesai**.

Periode survei dibuat relatif terhadap tanggal seeding: mulai 3 bulan sebelumnya dan berakhir 1 bulan setelahnya. Tanggal respons tersebar secara relatif agar tren dapat dicoba. ID, token tautan, dan tanggal antarinstalasi dapat berbeda, walaupun isi contoh sama.

Catatan penggunaan seeder:

- `DatabaseSeeder` dijalankan oleh `migrate --seed`; pencocokan teks pertanyaan mencegah penambahan bank pertanyaan bawaan yang sama.
- `DemoSeeder` hanya boleh berjalan pada `APP_ENV=local` atau `testing` dan memerlukan minimal satu admin. Tindak lanjut demo ditugaskan ke admin pertama yang ditemukan.
- Jika sudah ada survei bertanda demo, `DemoSeeder` melewati pembuatan seluruh paket demo. Perintah ini bukan alat sinkronisasi atau perbaikan data demo yang sudah diubah/dihapus sebagian.
- Gunakan filter sumber **Contoh** atau **Semua data** untuk melihat contoh. Filter **Operasional** mengecualikannya.
- Data yang diinput manual melalui UI tidak otomatis menjadi bagian seeder, meskipun namanya memuat kata “contoh”.

## Cara mencoba sistem

### Sebagai admin

1. Login, buka Dashboard, lalu periksa angka data demo tanpa filter tanggal.
2. Buka Kuesioner dan lihat survei contoh. Pertanyaannya sudah terkunci karena sudah memiliki undangan.
3. Duplikasi survei jika ingin mengubah pertanyaan. Hasil duplikasi mulai sebagai draf dan tidak menyalin undangan/respons.
4. Sesuaikan judul, periode, dan pertanyaan; gunakan pratinjau, kemudian aktifkan survei.
5. Tambahkan klien pada menu Klien dan buat tautan pada Distribusi. Membuat tautan belum mengirim email.
6. Periksa hasil pada Respons/Laporan dan kelola penanggung jawab, tenggat, serta catatan pada Tindak Lanjut.

### Sebagai responden

1. Pada Distribusi, salin tautan undangan demo milik **Lentera Travel** untuk mencoba dari awal, atau **Sagara Media** untuk melanjutkan draf.
2. Buka tautan pada tab privat/incognito di komputer yang menjalankan aplikasi. Responden tidak perlu login.
3. Isi jawaban. Rating 1–3 memerlukan komentar sebelum pengiriman final. Draf dapat disimpan tanpa menyelesaikan semua pertanyaan wajib.
4. Kirim jawaban dan periksa hasil di dashboard admin. Undangan yang sudah selesai tidak dapat mengirim respons final kedua.

Setelah melakukan uji pengisian, angka demo akan berubah. Tautan `127.0.0.1` hanya menunjuk komputer yang membukanya, sehingga tidak bisa dikirim begitu saja ke komputer teman. Untuk pengembangan masing-masing, teman menjalankan aplikasinya sendiri; untuk UAT bersama gunakan server yang dapat dijangkau tim.

### Aturan skor dan laporan

- Skor respons adalah rata-rata jawaban rating yang terisi; rating opsional kosong tidak dihitung.
- Skor keseluruhan adalah rata-rata skor respons; persentase kepuasan = skor / 5 × 100.
- Skor kategori adalah rata-rata jawaban rating pada kategori tersebut. Teks dan pilihan tidak masuk perhitungan skor.
- Respons mengikuti tanggal pengiriman jawaban. Response rate memakai undangan yang dibuat pada periode filter, dengan status penyelesaian saat laporan dilihat.
- Minimal satu rating 1–3 menghasilkan satu tindak lanjut untuk respons tersebut. Penyelesaian tindak lanjut memerlukan catatan.
- Ekspor berupa CSV UTF-8 yang dapat dibuka di Excel, bukan file `.xlsx`. PDF dibuat melalui fitur cetak browser.

## Akun dan hak akses

```sh
php artisan suara:user --role=admin
php artisan suara:user --role=viewer
```

| Peran | Akses |
| --- | --- |
| Admin | Mengelola data dan distribusi survei |
| Viewer | Membaca dashboard, respons, laporan, dan daftar tindak lanjut; akses baca lintas klien |
| Responden | Mengisi survei melalui tautan undangan tanpa akun dashboard |

Perintah di atas membuat akun baru dengan email unik; bukan perintah untuk mengganti password akun yang sudah ada. Source bawaan belum menyediakan halaman manajemen user, reset password melalui email, SSO, atau 2FA.

## Email dan reminder

Setup lokal membiarkan `SURVEY_EMAIL_ENABLED=false`. Pengujian survei melalui salin tautan tetap dapat dilakukan.

Untuk integrasi SMTP, gunakan konfigurasi yang diberikan pengelola email perusahaan pada `.env` lokal/server, bukan di GitHub:

```dotenv
APP_URL=https://survey.perusahaan.co.id
MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=smtp.perusahaan.co.id
MAIL_PORT=587
MAIL_USERNAME="ISI_AKUN_SMTP"
MAIL_PASSWORD="ISI_PASSWORD_SMTP"
MAIL_FROM_ADDRESS=survey@perusahaan.co.id
MAIL_FROM_NAME="${APP_NAME}"
SURVEY_EMAIL_ENABLED=true
SURVEY_REMINDER_DAYS=3
SURVEY_MAX_REMINDERS=3
QUEUE_CONNECTION=database
```

Sesuaikan host, port, dan skema dengan relay perusahaan; contoh tersebut bukan kredensial yang siap dipakai. Setelah perubahan konfigurasi jalankan `php artisan config:clear`.

Untuk pengujian lokal email, jalankan worker dan scheduler di dua terminal terpisah selain terminal web server:

```sh
php artisan queue:work --tries=3 --timeout=60
```

```sh
php artisan schedule:work
```

Pengiriman awal dipicu melalui tombol Kirim email. Setelah pengiriman awal berhasil, reminder otomatis diproses sesuai jadwal dan konfigurasi untuk survei aktif yang belum selesai. Data demo tetap tidak boleh dikirim email, walaupun SMTP diaktifkan. Gunakan data uji terpisah dan alamat penerima yang disetujui untuk pengujian SMTP.

## Menjalankan kembali dan mengambil update

Jika proyek sudah terpasang dan tidak ada perubahan source, cukup:

```sh
php artisan serve
```

Untuk mengambil perubahan tim, pastikan pekerjaan lokal sudah disimpan dalam commit atau stash. Contoh bila cabang utama bernama `main`:

```sh
git switch main
git pull --ff-only origin main
composer install
php artisan config:clear
php artisan migrate
php artisan view:clear
```

Migration mengaplikasikan perubahan struktur yang belum dijalankan. Tidak perlu mengulang setup lokal, membuat key, atau menjalankan demo seeder setiap kali pull. Baca catatan PR apabila perubahan memerlukan tambahan variabel `.env` atau langkah khusus.

**Jangan menggunakan `php artisan migrate:fresh` untuk update rutin:** perintah itu menghapus seluruh tabel dan data pada database yang sedang dikonfigurasi.

## Struktur proyek dan tampilan

| Lokasi | Isi |
| --- | --- |
| `app/Http/Controllers/` | Penanganan request dan halaman |
| `app/Models/` | Model database |
| `app/Services/` | Logika respons, laporan, dan layanan aplikasi |
| `app/Console/Commands/` | Pembuatan user dan perintah email/reminder |
| `database/migrations/` | Struktur tabel |
| `database/seeders/` | Bank pertanyaan dan data demo yang dapat dibuat ulang |
| `resources/views/` | Template Blade |
| `public/assets/` | CSS dan JavaScript |
| `routes/web.php` | Rute aplikasi |
| `routes/console.php` | Jadwal perintah otomatis |
| `scripts/setup-local.php` | Persiapan SQLite lokal |
| `tests/Feature/` | Pengujian alur aplikasi |
| `docs/` | Deployment, requirement, UAT, dan catatan verifikasi paket awal |

Tabel bisnis utama: `clients`, `surveys`, `questions`, `bank_questions`, `invitations`, `survey_responses`, `answers`, `follow_ups`, dan `email_deliveries`. Tabel `users` menyimpan akun; tabel session/cache/queue digunakan Laravel.

Untuk versi UI Transsurvey yang sudah dikustomisasi, commit juga file aktual yang digunakan, misalnya:

- `public/images/logo-tcid.png` untuk logo.
- `public/assets/transsurvey.css` untuk tema merah dan font sistem.
- `public/assets/ui-enhancements.js` bila peningkatan dropdown/dialog sudah dipasang.
- Template brand, layout, login, survei publik, dan halaman terima kasih yang sudah diubah.

Daftar ini menunjukkan lokasi kustomisasi yang direncanakan/digunakan tim; keberadaan dan perilakunya harus mengikuti source yang benar-benar di-commit. README tidak memasang atau mengubah UI. `APP_NAME` hanya memengaruhi tampilan yang membaca konfigurasi; teks hardcoded perlu diubah pada templatenya.

Jika Tom Select dan SweetAlert2 dimuat melalui CDN, browser membutuhkan akses internet ke CDN tersebut. Untuk lingkungan tanpa internet, sertakan aset vendor lokal dan sesuaikan referensi pada layout. Hindari memuat script yang sama dua kali.

Font sistem seperti `-apple-system, BlinkMacSystemFont, system-ui, "Segoe UI", sans-serif` mengikuti font perangkat: perangkat Apple memakai font sistem Apple, sedangkan Windows memakai fallback. Logo sebaiknya memiliki lebar terkontrol, tinggi otomatis, dan latar putih.

## Membagikan data tambahan

### Data contoh bawaan: cukup commit seeder

Untuk mendapatkan data yang dijelaskan dalam tabel demo, teman hanya perlu migration, akun admin, dan `DemoSeeder`. Setiap komputer memiliki database serta `APP_KEY` sendiri. Tautan undangan dibuat ulang secara lokal.

### Data yang dibuat manual setelah instalasi

Data tambahan pada komputer pemilik proyek **tidak tercakup otomatis dalam README atau seeder bawaan**. Agar dapat direproduksi tim, tambahkan seeder khusus menggunakan data dummy yang sudah diperiksa. Jangan mengasumsikan semua data yang terlihat di dashboard sudah ada dalam `DemoSeeder`.

Jika tim membutuhkan salinan persis database pengembangan yang sudah berjalan:

1. Hentikan sementara penulisan data dan worker agar backup konsisten.
2. Untuk MySQL, ekspor struktur dan data menggunakan alat database seperti phpMyAdmin atau `mysqldump`; untuk SQLite, gunakan backup database konsisten setelah aplikasi berhenti menulis. Jangan hanya menyalin file SQLite yang sedang aktif tanpa memperhatikan file WAL.
3. Periksa isi backup: akun, email, respons, tautan undangan, session, dan antrean email dapat ikut terbawa. Bagikan hanya data yang memang boleh diakses rekan, melalui kanal internal terbatas; jangan commit backup mentah ke repository.
4. Restore ke database lokal baru yang terpisah. Jangan menjalankan demo seeder pada salinan ini jika tujuan Anda mempertahankan isi persis.
5. Database berisi token undangan terenkripsi. Untuk memakai ciphertext yang sama, instalasi tujuan memerlukan `APP_KEY` asli melalui pengelolaan secret yang disetujui tim. Jangan menaruh key di README/GitHub dan jangan membuat key baru setelah restore. Jika key tidak boleh dibagikan, gunakan seeder dummy dengan token baru alih-alih menyalin database terenkripsi.
6. Sesuaikan koneksi database, `APP_URL`, dan set `SURVEY_EMAIL_ENABLED=false` pada instalasi tujuan sebelum menjalankan aplikasi. Jangan menjalankan worker/scheduler hasil restore sebelum memeriksa antreannya.

Seeder adalah jalur default untuk kolaborasi pengembangan. Salinan database tidak otomatis tersinkron saat seseorang melakukan `git pull`.

## Upload pertama ke GitHub

Bagian ini untuk pemilik proyek. Gunakan folder proyek terbaru di komputer Anda, yaitu folder yang berisi `artisan`, `composer.json`, serta perubahan UI terakhir. Letakkan README ini sebagai `README.md` di folder tersebut.

### 1. Siapkan file yang dibagikan

Commit source, migration, seeder, `composer.json`, **`composer.lock`**, aset UI/logo, dokumentasi, dan `.env.example` yang sudah dibersihkan. Pada `.env.example`, gunakan `APP_NAME=Transsurvey`, biarkan `APP_KEY` kosong, dan jangan memasukkan password asli.

Gabungkan pola berikut dengan `.gitignore` yang sudah ada agar konfigurasi pribadi, backup, dan file runtime tidak ikut terunggah. Pertahankan aturan proyek lain yang masih dibutuhkan:

```gitignore
/.env
/.env.*
!/.env.example
/auth.json
/vendor/
/node_modules/
/public/hot
/bootstrap/cache/*.php
/storage/logs/*
!/storage/logs/.gitignore
/storage/framework/views/*
!/storage/framework/views/.gitignore
/storage/framework/sessions/*
!/storage/framework/sessions/.gitignore
/storage/framework/cache/data/*
!/storage/framework/cache/data/.gitignore
/storage/app/private/*
!/storage/app/private/.gitignore
/.phpunit.cache/
*.sqlite
*.sqlite-*
*.sqlite3
*.sqlite3-*
*.sql
*.sql.gz
/backups/
/.idea/
/.vscode/
```

Pola `.gitignore` tidak menghapus file yang sudah telanjur dilacak Git. Periksa staged files sebelum commit. Jika secret pernah di-push, menghapus file pada commit berikutnya saja tidak cukup: secret perlu diganti dan riwayat ditangani sesuai prosedur tim.

### 2. Buat repository dan upload

Di GitHub, buat repository baru, misalnya `transsurvey`. Pilih **Private** untuk proyek internal. Untuk alur push di bawah, biarkan repository remote kosong tanpa README, license, atau `.gitignore` otomatis.

Jika folder lokal belum menggunakan Git, jalankan dari root proyek:

```sh
git init -b main
git add .
git status
git diff --cached --stat
```

Periksa daftar file terlebih dahulu. Pastikan `.env`, database, backup, password, dan file runtime tidak termasuk. Setelah benar:

```sh
git commit -m "Initial commit: Transsurvey"
git remote add origin https://github.com/USERNAME-ATAU-ORG/transsurvey.git
git push -u origin main
```

Ganti URL dengan URL repository sebenarnya dan ikuti autentikasi GitHub yang digunakan tim. Jangan menaruh token akses dalam URL yang di-commit atau dokumentasi.

Jika proyek sudah memiliki Git/remote, periksa `git status`, `git branch --show-current`, dan `git remote -v`; sesuaikan langkah dengan repository tersebut. Jangan mengulang pembuatan `origin` atau memaksa push untuk mengatasi konflik.

### 3. Undang collaborator

Untuk repository milik akun pribadi: buka repository di GitHub → **Settings → Collaborators → Add people**, pilih username teman, lalu kirim undangan. Teman perlu menerima undangan sebelum mengakses repository private.

Untuk repository organisasi, pengaturan bisa berada di **Collaborators & teams** dan mengikuti kebijakan akses organisasi. Hak akses GitHub tidak otomatis membuat akun login dalam aplikasi.

## Kolaborasi tim

Kerjakan perubahan pada branch terpisah. Contoh:

```sh
git switch main
git pull --ff-only origin main
git switch -c feature/perbaikan-login
```

Setelah perubahan selesai dan diperiksa:

```sh
git add .
git diff --cached --stat
git commit -m "Rapikan tampilan login"
git push -u origin feature/perbaikan-login
```

Buat Pull Request di GitHub menuju `main`, jelaskan perubahan serta cara mengujinya, lalu minta rekan meninjau. Sertakan screenshot untuk perubahan UI. Hindari mengedit file yang sama secara bersamaan tanpa koordinasi.

Untuk perubahan database, buat migration baru agar rekan dapat menjalankannya dengan `php artisan migrate`. Jangan hanya mengubah struktur secara manual di phpMyAdmin atau mengubah migration lama yang sudah digunakan tim. Simpan perubahan data dummy dalam seeder, dan jelaskan apakah perlu dijalankan setelah pull.

## Pengujian

```sh
composer test
```

Konfigurasi `phpunit.xml` bawaan memakai SQLite in-memory, sehingga test tidak menggunakan database kerja. Pertahankan konfigurasi testing tersebut. PHP tetap memerlukan extension SQLite walaupun aplikasi sehari-hari memakai MySQL.

Pengujian fitur mencakup akses admin/viewer, komentar wajib untuk rating rendah, perhitungan skor, draf, pencegahan respons/undangan ganda, status survei, pengiriman email dengan mock, laporan, CSV, dan tindak lanjut.

Untuk pemeriksaan manual awal:

- Login berhasil dan dashboard menunjukkan hasil seeder yang diharapkan.
- Logo tidak meluber, tema konsisten, dan halaman nyaman pada layar kecil.
- Survei melalui tautan bisa dibuka tanpa login.
- Rating 1–3 tanpa komentar ditolak saat pengiriman final.
- Draf bisa dilanjutkan; pengiriman final menambah respons dan tindak lanjut sesuai aturan.
- Ekspor laporan dapat dibuka dan filter sumber data bekerja.

Lihat `docs/UAT.md` untuk skenario tambahan. `docs/VERIFICATION.md` mencatat verifikasi paket awal, bukan jaminan semua perubahan lokal berikutnya sudah diuji.

## Troubleshooting

| Masalah | Langkah pemeriksaan |
| --- | --- |
| `php` atau `composer` tidak dikenali | Periksa instalasi dan PATH; buka ulang terminal setelah mengubah PATH |
| Dependency meminta versi PHP berbeda | Periksa `php -v`, `where.exe php` di Windows, dan `composer check-platform-reqs`; gunakan PHP kompatibel dengan lock file |
| `could not find driver` | Aktifkan `pdo_sqlite` atau `pdo_mysql` pada `php.ini` yang ditunjukkan `php --ini`, lalu restart proses PHP |
| `vendor/autoload.php` tidak ditemukan | Jalankan `composer install` dari root proyek |
| `.env sudah tersedia` saat setup | Script melindungi konfigurasi lama; periksa `.env` lalu lanjutkan langkah instalasi yang belum selesai |
| `No application encryption key` | Untuk database baru saja, jalankan `php artisan key:generate`; untuk database hasil restore gunakan key asli |
| Tabel/session/cache belum ada | Periksa koneksi `.env`, jalankan `php artisan config:clear` lalu `php artisan migrate` |
| MySQL access denied / unknown database | Periksa service MySQL, nama database, username, password, port, dan izin user |
| `Buat admin terlebih dahulu` | Jalankan `php artisan suara:user --role=admin`, kemudian ulangi `DemoSeeder` |
| Email sudah terpakai saat membuat user | Gunakan email berbeda; perintah membuat user tidak memperbarui akun lama |
| Data demo tidak terlihat | Pilih sumber Contoh/Semua data, reset filter periode, dan pastikan `DemoSeeder` pernah dijalankan pada database yang benar |
| Survei demo sudah tidak menerima respons | Periksa status/periode; tanggal berakhir demo adalah satu bulan setelah seeding, dan tidak diperbarui otomatis |
| Logo rusak atau terlalu besar | Pastikan aset di-commit, cek `/images/logo-tcid.png`, cek huruf besar/kecil nama file, dan batasi ukuran gambar pada CSS |
| Tema atau dropdown masih lama | Pastikan CSS/JS terbaru di-commit dan dimuat layout, cek error browser/CDN, jalankan `php artisan view:clear`, lalu hard refresh |
| Error `filemtime` untuk aset | File yang dirujuk belum ada atau path keliru; commit file CSS/JS yang dipakai template |
| Error 419 saat submit | Muat ulang halaman, gunakan host yang konsisten, periksa session/tabel sessions dan `SESSION_SECURE_COOKIE=false` untuk HTTP lokal |
| Port 8000 sudah digunakan | Jalankan `php artisan serve --port=8001`, sesuaikan `APP_URL`, lalu `php artisan config:clear` |
| Tautan undangan gagal didekripsi | Periksa apakah `APP_KEY` berbeda dari key saat data dibuat; jangan membuat key baru untuk mencoba memperbaikinya |
| Error server lain | Periksa `storage/logs/laravel.log`; bagikan bagian error yang relevan tanpa secret atau data klien |

## Deployment dan referensi

GitHub menyimpan source dan mendukung kolaborasi; push repository tidak otomatis menjalankan aplikasi Laravel untuk pengguna. **GitHub Pages tidak menjalankan backend PHP Laravel.** Gunakan server dengan PHP/database dan document root mengarah ke folder `public/` untuk deployment aplikasi ini.

Lihat:

- [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md): deployment, worker, scheduler, dan konfigurasi produksi.
- [`docs/REQUIREMENTS.md`](docs/REQUIREMENTS.md): cakupan requirement.
- [`docs/UAT.md`](docs/UAT.md): skenario uji pengguna.
- [Laravel 13: release notes](https://laravel.com/docs/13.x/releases).
- [GitHub: upload source lokal](https://docs.github.com/en/migrations/importing-source-code/using-the-command-line-to-import-source-code/adding-locally-hosted-code-to-github).
- [GitHub: undang collaborator](https://docs.github.com/en/repositories/managing-your-repositorys-settings-and-features/repository-access-and-collaboration/inviting-collaborators-to-a-personal-repository).
- [GitHub Pages](https://docs.github.com/en/pages/getting-started-with-github-pages/about-github-pages).

Source bawaan belum terhubung dengan EMS atau Transdesk. Data dan akun dari situs preview terpisah tidak otomatis masuk ke instalasi Laravel ini. Ikuti ketentuan tim/perusahaan untuk penggunaan source dan logo.
