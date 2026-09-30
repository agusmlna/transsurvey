<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Sama seperti DemoSeeder: jangan sampai jalan di produksi.
        if (!app()->environment('local', 'testing')) {
            throw new \RuntimeException('UserSeeder hanya boleh dijalankan di lingkungan local/testing.');
        }

        // Password dari .env (SEED_USER_PASSWORD), atau dibuat acak jika kosong.
        $generated = false;
        $password = env('SEED_USER_PASSWORD');
        if (!$password) {
            $password = Str::password(16);
            $generated = true;
        }

        $users = [
            ['name' => 'Admin Lokal',  'email' => 'admin@example.test',  'role' => 'admin'],
            ['name' => 'Viewer Lokal', 'email' => 'viewer@example.test', 'role' => 'viewer'],
        ];

        foreach ($users as $data) {
            // Jangan pakai Hash::make: model User sudah punya cast 'password' => 'hashed',
            // sehingga hash ganda akan membuat login gagal.
            User::updateOrCreate(
                ['email' => $data['email']],
                ['name' => $data['name'], 'role' => $data['role'], 'password' => $password]
            );
        }

        $this->command?->info('User seeder selesai: 1 admin, 1 viewer.');
        if ($generated) {
            $this->command?->warn('Password acak (simpan sekarang, tidak ditampilkan lagi): '.$password);
        }
    }
}