<?php
// Local SQLite setup only. Never replaces an existing .env or application key.
if (PHP_VERSION_ID < 80300) {
    fwrite(STDERR, "Gunakan PHP 8.3 atau lebih baru.\n"); exit(1);
}
foreach (['pdo_sqlite','mbstring','openssl','fileinfo','dom','xml','curl'] as $extension) {
    if (!extension_loaded($extension)) {
        fwrite(STDERR, "Aktifkan extension PHP: {$extension}\n"); exit(1);
    }
}
chdir(dirname(__DIR__));
if (file_exists('.env')) {
    fwrite(STDERR, ".env sudah tersedia. Setup dihentikan agar konfigurasi lama tetap aman. Ikuti README untuk melanjutkan.\n"); exit(1);
}
$env = file_get_contents('.env.example');
$dbPath = str_replace('\\', '/', getcwd().'/database/database.sqlite');
$env = preg_replace('/^DB_CONNECTION=.*$/m', 'DB_CONNECTION=sqlite', $env);
$env = preg_replace('/^DB_DATABASE=.*$/m', 'DB_DATABASE="'.$dbPath.'"', $env);
file_put_contents('.env', $env);
if (!file_exists($dbPath)) { touch($dbPath); }
foreach (['storage/framework/views','storage/framework/sessions','storage/framework/cache/data','storage/logs','bootstrap/cache'] as $directory) {
    if (!is_dir($directory)) { mkdir($directory, 0775, true); }
}
echo ".env lokal SQLite sudah dibuat.\n";
echo "Lanjutkan: php artisan key:generate\nphp artisan migrate --seed\nphp artisan suara:user\nphp artisan db:seed --class=DemoSeeder\nphp artisan serve\n";
