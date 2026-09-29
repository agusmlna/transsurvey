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
        // Sama seperti DemoSeeder: jangan sampai jalan di  production
        if (!app()->environment('local', 'testing')){
            throw new \RuntimeException('UserSeeder hanya boleh dijalankan di lingkungan local/testing');
        }

        // Password dari .env (SEED_USER_PASSWORD). atau dibuat acak jika kosong
        $generate = false;
        $password = env('SEED_USER_PASSWORD');
        if(!$password){
            $password = Str::password(16);
            $generate = true;
        }

        $users = [
            ['name' => 'Admin Lokal',  'email' => 'admin@example.test',  'role' => 'admin'],
            ['name' => 'Viewer Lokal', 'email' => 'viewer@example.test', 'role' => 'viewer'],
        ];

        foreach ($users as $data){
            // jangan pakai Hash:make; model User sudah punya cast 'password' => 'hashed',
            // sehingga hash ganda akan membuat login gagal
            User::updateOrCreate(
                ['email' => $data['email']],
                ['name' => $data['name'], 'role' => $data['role'], 'password' => $password]
            );
        }

        $this->command?->info('User seeder selesai: 1 admin, 1 viewer');
        if($generate){
            $this->command?->warn('Password acak (simpan sekarang, tidak ditampilkan lagi): '. $password);
        }
    }
}