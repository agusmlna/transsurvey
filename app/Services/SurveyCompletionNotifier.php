<?php

namespace App\Services;

use App\Jobs\SendSurveyCompletionEmail;
use App\Models\{AdminSurveyNotification, SurveyResponse, User};
use Illuminate\Support\Facades\{DB, Log};

class SurveyCompletionNotifier
{
    // Called inside the response transaction: rollback also removes these records.
    public function record(SurveyResponse $response): void
    {
        $invitation = $response->invitation;
        if ($invitation->is_demo || $response->survey->is_demo || $response->client->is_demo) {
            return;
        }

        $ids = [];
        foreach (User::where('role', 'admin')->get() as $admin) {
            if (! filter_var($admin->email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $notification = AdminSurveyNotification::firstOrCreate([
                'survey_response_id' => $response->id,
                'user_id' => $admin->id,
            ], ['recipient_email' => $admin->email]);
            if ($notification->wasRecentlyCreated) {
                $ids[] = $notification->id;
            }
        }

        DB::afterCommit(function () use ($ids) {
            foreach ($ids as $id) {
                $this->dispatch($id);
            }
        });
    }

    public function dispatch(int $id): bool
    {
        if (! config('survey.email_enabled') || config('mail.default') !== 'smtp') {
            return false;
        }
        // A recoverable queue problem must not turn a saved response into a failed submission.
        try {
            $claimed = AdminSurveyNotification::whereKey($id)
                ->whereIn('status', ['pending', 'failed'])
                ->where(fn ($q) => $q->whereNull('queued_at')->orWhere('queued_at', '<=', now()->subMinutes(10)))
                ->update(['status' => 'pending', 'queued_at' => now()]);
            if (! $claimed) {
                return false;
            }
            // Explicit database connection keeps SMTP outside the public HTTP request,
            // even if a local .env accidentally uses QUEUE_CONNECTION=sync.
            SendSurveyCompletionEmail::dispatch($id)->onConnection('database');
            return true;
        } catch (\Throwable $exception) {
            AdminSurveyNotification::whereKey($id)->where('status', 'pending')->update(['queued_at' => null]);
            Log::warning('Notifikasi survei belum masuk antrean; jalankan surveys:retry-admin-notifications.', [
                'notification_id' => $id, 'exception_type' => get_class($exception),
            ]);
            return false;
        }
    }
}
