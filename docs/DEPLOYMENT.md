# Deployment server kantor

Gunakan server aplikasi khusus atau virtual host terpisah. Paket ini tidak mengubah konfigurasi EMS/Transdesk.

## Kebutuhan

- PHP 8.3+ CLI dan PHP-FPM/Apache dengan extension Laravel, `pdo_mysql`, serta extension untuk pengujian bila dibutuhkan.
- MySQL dengan database kosong khusus aplikasi; backup dan akun aplikasi terpisah.
- Composer 2; akses dependency saat build/install. Tidak perlu Node.js.
- HTTPS, DNS yang dapat dijangkau target klien, SMTP relay jika email akan digunakan.
- Worker antrean dan scheduler yang dikelola oleh service manager.

## Pemasangan

1. Ekstrak proyek ke `/var/www/suara`. Buat `.env` dari contoh bila belum ada, isi database dan URL.
2. Jalankan `composer install --no-dev --optimize-autoloader` menggunakan user deployment. Pertahankan `composer.lock` dari build yang telah diuji.
3. Hanya pada instalasi baru: `php artisan key:generate`. Simpan APP_KEY dengan aman bersama backup konfigurasi. Jangan menggantinya saat update.
4. Jalankan `php artisan migrate --force`, lalu `php artisan db:seed --force` untuk bank pertanyaan. Seeder ini tidak membuat user atau data contoh.
5. Jalankan `php artisan suara:user` untuk membuat admin.
6. Atur environment produksi:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://survey.perusahaan.co.id
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=database
```

7. User web server dan worker harus dapat menulis `storage` serta `bootstrap/cache`. Gunakan group/ownership sesuai kebijakan server, jangan memberikan izin `777`.
8. Jalankan `php artisan optimize`. Uji login, formulir klien, laporan, dan worker pada URL sebenarnya.

## Web server

Document root harus menunjuk **`/var/www/suara/public`**, bukan root proyek. File `.env`, database, log, dan source lain tidak boleh dilayani langsung.

Apache: aktifkan `mod_rewrite`, izinkan aturan `public/.htaccess`, dan arahkan VirtualHost HTTPS ke folder public. TLS mengikuti sertifikat perusahaan.

Contoh bagian routing Nginx (tambahkan pada virtual host HTTPS yang dikelola tim server):

```nginx
root /var/www/suara/public;
index index.php;
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
location = /index.php {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/index.php;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
}
location ~ \.php$ { return 404; }
location ~ /\.(?!well-known).* { deny all; }
```

Sesuaikan socket versi PHP. Jika menggunakan load balancer/reverse proxy, konfigurasikan trusted proxy khusus alamat proxy yang dikendalikan perusahaan dalam `bootstrap/app.php`, kemudian verifikasi URL HTTPS dan IP pembatasan login. Jangan mempercayai header dari sembarang sumber.

## Queue worker

Contoh `/etc/systemd/system/suara-worker.service` (sesuaikan user dan lokasi PHP):

```ini
[Unit]
Description=Suara Survey Queue Worker
After=network.target

[Service]
User=www-data
Group=www-data
WorkingDirectory=/var/www/suara
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3 --timeout=60
Restart=always
RestartSec=5
TimeoutStopSec=90

[Install]
WantedBy=multi-user.target
```

Aktifkan service melalui administrator server. Pada setiap deployment/config update, jalankan `php artisan queue:restart` agar worker membaca perubahan. Queue retry_after 120 detik sudah lebih besar dari timeout worker 60 detik.

## Scheduler

Tambahkan pada crontab user yang dapat mengakses aplikasi:

```cron
* * * * * cd /var/www/suara && /usr/bin/php artisan schedule:run >> /var/www/suara/storage/logs/scheduler.log 2>&1
```

Atur rotasi `scheduler.log`. Scheduler menjalankan reminder setiap jam dan memulihkan email pending setiap lima menit. Worker tetap dibutuhkan agar job benar-benar dikirim.

## Operasional

- Sebelum mengaktifkan SMTP, uji pada mailbox internal dengan satu undangan operasional khusus UAT. Data contoh memang tidak dapat dikirim.
- Log aplikasi berada di `storage/logs`, status email ada pada Distribusi. `php artisan queue:failed` menampilkan job gagal.
- Untuk mencoba ulang email gagal, gunakan tombol pengiriman pada undangan yang sama. Hindari retry massal sebelum mengetahui penyebab gagal.
- Backup database dan `.env`/APP_KEY secara aman. Uji restore termasuk kemampuan membuka token undangan.
- Update source: backup → deploy dependency berdasarkan lock → migrate → optimize → queue:restart → smoke test.
- Jangan menjalankan `migrate:fresh` pada database operasional karena akan menghapus tabel.
- Belum ada audit trail lengkap, multi-tenant, integrasi EMS/GLPI, dan kebijakan retensi otomatis. Tentukan kebutuhan tersebut sebelum pemakaian skala luas.

Laporan saat ini memuat respons sesuai filter ke memori untuk menghitung statistik. Pada volume besar, gunakan filter periode dan evaluasi agregasi SQL/pagination laporan sebelum perluasan pemakaian.

Referensi: https://laravel.com/docs/13.x/deployment, https://laravel.com/docs/13.x/queues, https://laravel.com/docs/13.x/scheduling.
