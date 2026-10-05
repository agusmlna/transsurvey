<?php

namespace App\Jobs;

use App\Mail\SurveyCompletedMail;
use App\Models\AdminSurveyNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\{DB, Log, Mail};

class SendSurveyCompletionEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(public int $notificationId) {}
    public function backoff(): array { return [60, 180, 300]; }

    public function handle(): void
    {
        $notification = AdminSurveyNotification::with(['user', 'response.invitation', 'response.client', 'response.survey'])
            ->find($this->notificationId);
        // 'sending' needs review if a worker stopped during delivery. Never resend it blindly.
        if (! $notification || ! in_array($notification->status, ['pending', 'failed'], true)) {
            return;
        }
        $response = $notification->response;
        $admin = $notification->user;
        if (! $admin || $admin->role !== 'admin'
            || $admin->email !== $notification->recipient_email
            || ! $response || ! $response->invitation?->completed_at
            || $response->invitation->is_demo || $response->client->is_demo || $response->survey->is_demo) {
            $this->writeStatus(['pending', 'failed'], ['status' => 'skipped']);
            return;
        }
        if (! config('survey.email_enabled') || config('mail.default') !== 'smtp') {
            throw new \RuntimeException('Pengiriman email SMTP belum diaktifkan.');
        }

        // One short, atomic write claims this record. No database transaction spans SMTP.
        if (! $this->writeStatus(['pending', 'failed'], ['status' => 'sending'])) {
            return;
        }
        try {
            Mail::to($notification->recipient_email)->send(new SurveyCompletedMail($response, $admin->name));
        } catch (\Throwable $exception) {
            // A transport error may be retried. If this write also fails, keep 'sending'
            // for manual review instead of risking an uncontrolled duplicate.
            $this->writeStatus(['sending'], ['status' => 'failed', 'queued_at' => null]);
            throw $exception;
        }

        try {
            // Retry only this database write, never the already accepted SMTP operation.
            $this->writeStatus(['sending'], ['status' => 'sent', 'sent_at' => now()]);
        } catch (\Throwable $exception) {
            Log::error('Email admin telah diterima SMTP tetapi status belum tersimpan. Periksa inbox sebelum retry.', [
                'notification_id' => $this->notificationId,
                'exception_type' => get_class($exception),
            ]);
            // 'sending' remains protected even when the queue retries this job.
            throw $exception;
        }
    }

    private function writeStatus(array $from, array $values): int
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return AdminSurveyNotification::whereKey($this->notificationId)
                    ->whereIn('status', $from)->update($values);
            } catch (QueryException $exception) {
                $sqliteBusy = DB::connection()->getDriverName() === 'sqlite'
                    && in_array(((int) ($exception->errorInfo[1] ?? 0)) & 255, [5, 6], true);
                if (! $sqliteBusy || $attempt >= 4) { throw $exception; }
                usleep(100000 * ($attempt + 1));
            }
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $this->writeStatus(['pending', 'failed'], ['status' => 'failed', 'queued_at' => null]);
    }
}
