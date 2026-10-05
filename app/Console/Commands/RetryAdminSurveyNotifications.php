<?php

namespace App\Console\Commands;

use App\Models\AdminSurveyNotification;
use App\Services\SurveyCompletionNotifier;
use Illuminate\Console\Command;

class RetryAdminSurveyNotifications extends Command
{
    protected $signature = 'surveys:retry-admin-notifications';
    protected $description = 'Antrekan ulang notifikasi admin yang tertunda atau gagal.';

    public function handle(SurveyCompletionNotifier $notifier): int
    {
        if (! config('survey.email_enabled') || config('mail.default') !== 'smtp') {
            $this->error('Aktifkan SMTP dan SURVEY_EMAIL_ENABLED terlebih dahulu.');
            return self::FAILURE;
        }
        $count = 0;
        AdminSurveyNotification::whereIn('status', ['pending', 'failed'])->orderBy('id')
            ->chunkById(100, function ($notifications) use ($notifier, &$count) {
                foreach ($notifications as $notification) {
                    if ($notifier->dispatch($notification->id)) { $count++; }
                }
            });
        $this->info("{$count} notifikasi admin masuk antrean. Jalankan worker database untuk mengirimnya.");
        return self::SUCCESS;
    }
}
