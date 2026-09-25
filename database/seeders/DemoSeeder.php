<?php
namespace Database\Seeders;

use App\Models\{Client, Invitation, Survey, User};
use App\Services\ResponseService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (!app()->environment('local', 'testing')) {
            throw new \RuntimeException('DemoSeeder hanya boleh dijalankan di lingkungan local/testing.');
        }
        $admin = User::where('role', 'admin')->first();
        if (!$admin) {
            throw new \RuntimeException('Buat admin terlebih dahulu: php artisan suara:user');
        }
        if (Survey::where('is_demo', true)->exists()) {
            $this->command?->info('Data contoh sudah tersedia; tidak ditambahkan lagi.');
            return;
        }
        DB::transaction(function () use ($admin) {
            $this->call(DatabaseSeeder::class);
            $survey = Survey::create([
                'title' => 'Contoh — Survei Kepuasan Klien',
                'description' => 'Bantu kami memahami pengalaman Anda. Data pada survei ini hanya untuk mencoba aplikasi.',
                'status' => 'active', 'starts_at' => today()->subMonths(3), 'ends_at' => today()->addMonth(),
                'is_template' => true, 'is_demo' => true, 'created_by' => $admin->id,
            ]);
            foreach (DatabaseSeeder::questions() as $i => $question) {
                $survey->questions()->create($question + ['position' => $i + 1]);
            }
            $names = ['Nusantara Digital', 'Cakrawala Retail', 'Arunika Finance', 'Bumi Logistik', 'Sagara Media', 'Lentera Travel'];
            $ratings = [[5,4,5,4], [4,3,4,4], [3,2,4,3], [5,5,5,4]];
            foreach ($names as $i => $name) {
                $client = Client::create([
                    'name' => $name.' (Contoh)', 'contact' => 'PIC Contoh '.($i+1),
                    'email' => 'contoh'.($i+1).'@example.invalid', 'project' => ['Customer Care', 'Back Office', 'IT Support'][$i%3],
                    'active' => true, 'is_demo' => true,
                ]);
                $token = Str::random(64);
                $invitation = Invitation::create([
                    'survey_id' => $survey->id, 'client_id' => $client->id,
                    'recipient_name' => $client->contact, 'recipient_email' => $client->email,
                    'token' => $token, 'token_hash' => hash('sha256', $token), 'is_demo' => true,
                ]);
                $invitation->forceFill(['created_at' => now()->subMonths(2)])->save();
                if ($i < 4) {
                    $answers = [];
                    foreach ($survey->questions as $j => $question) {
                        $value = match ($question->type) {
                            'rating' => (string)$ratings[$i][$j],
                            'choice' => 'Email',
                            default => 'Mohon informasi progres layanan disampaikan secara berkala.',
                        };
                        $answers[$question->id] = ['value' => $value, 'comment' => $question->type === 'rating' && (int)$value <= 3 ? 'Waktu respons masih perlu ditingkatkan, terutama pada jam sibuk.' : ''];
                    }
                    $response = app(ResponseService::class)->save($invitation, ['answers' => $answers]);
                    $submitted = now()->subMonths(max(0, 2-$i));
                    $response->update(['submitted_at' => $submitted]);
                    $invitation->update(['started_at' => $submitted, 'completed_at' => $submitted]);
                    if ($response->followUp) {
                        $response->followUp->update(['assigned_to' => $admin->id, 'status' => 'in_progress', 'notes' => 'Contoh: Tim sedang mengevaluasi waktu respons.']);
                    }
                } elseif ($i === 4) {
                    $question = $survey->questions->first();
                    app(ResponseService::class)->save($invitation, ['answers' => [$question->id => ['value' => '4']]], true);
                }
            }
        });
        $this->command?->info('Data contoh dibuat: 6 klien, 4 respons, 1 draf, 1 belum mengisi. Email data contoh diblokir.');
    }
}
