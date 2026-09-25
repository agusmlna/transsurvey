<?php
namespace Database\Seeders;

use App\Models\BankQuestion;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::questions() as $question) {
            BankQuestion::firstOrCreate(['text' => $question['text']], $question);
        }
    }

    public static function questions(): array
    {
        return [
            ['text' => 'Seberapa puas Anda terhadap kualitas layanan yang kami berikan?', 'category' => 'Kualitas layanan', 'type' => 'rating', 'required' => true, 'options' => []],
            ['text' => 'Seberapa puas Anda terhadap kecepatan tim dalam merespons kebutuhan Anda?', 'category' => 'Responsivitas', 'type' => 'rating', 'required' => true, 'options' => []],
            ['text' => 'Seberapa puas Anda terhadap pengetahuan dan kemampuan tim kami?', 'category' => 'Kompetensi', 'type' => 'rating', 'required' => true, 'options' => []],
            ['text' => 'Seberapa jelas informasi dan pembaruan yang disampaikan tim kami?', 'category' => 'Komunikasi', 'type' => 'rating', 'required' => true, 'options' => []],
            ['text' => 'Kanal komunikasi mana yang paling nyaman untuk Anda?', 'category' => 'Preferensi', 'type' => 'choice', 'required' => false, 'options' => ['Email', 'Telepon', 'Pertemuan daring']],
            ['text' => 'Apa saran Anda agar layanan kami lebih baik?', 'category' => 'Saran', 'type' => 'text', 'required' => false, 'options' => []],
        ];
    }
}
