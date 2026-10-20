<?php

namespace App\Jobs;

use App\Models\{EmailDelivery, Invitation};
use App\Mail\SurveyInvitationMail;
use App\Services\SurveyAccessService;
use Illuminate\Contracts\Queue\{ShouldQueue, ShouldBeUnique};
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\{DB, Mail};

class SendSurveyEmail implements ShouldQueue, ShouldBeUnique
{
    use Queueable;
    public int $tries = 3;
    public int $timeout = 60;
    public int $uniqueFor = 600;
    public function __construct(public int $deliveryId) {}
    public function uniqueId(): string
    {
        return (string)$this->deliveryId;
    }
    public function backoff(): array
    {
        return [60, 180, 300];
    }
    public function handle(): void
    {
        $existing = EmailDelivery::find($this->deliveryId);
        if (!$existing) return;
        if (!config('survey.email_enabled') || config('mail.default') !== 'smtp') throw new \RuntimeException('SMTP sending is not enabled.');
        DB::transaction(function () use ($existing) {
            $inv = Invitation::with('survey')->lockForUpdate()->find($existing->invitation_id);
            if (!$inv) return;
            $delivery = EmailDelivery::lockForUpdate()->find($this->deliveryId);
            if (!$delivery || in_array($delivery->status, ['sent', 'skipped'])) return;
            $invalidStatusTemplate = ($delivery->kind === 'not_started' && (!$inv->sent_at || $inv->started_at))
                || ($delivery->kind === 'save_draft' && (!$inv->sent_at || !$inv->started_at));
            if ($inv->completed_at || $inv->is_demo || !$inv->survey->isOpen() || $invalidStatusTemplate || ($delivery->kind === 'reminder' && $inv->reminder_count >= config('survey.max_reminders'))) {
                $delivery->update(['status' => 'skipped']);
                return;
            }
            app(SurveyAccessService::class)->prepareForEmail($inv);
            Mail::to($inv->recipient_email)->send(new SurveyInvitationMail($inv, $delivery->kind === 'reminder', $delivery->kind));
            $delivery->update(['status' => 'sent', 'sent_at' => now(), 'last_error' => null]);
            if ($delivery->kind === 'invitation') {
                $inv->sent_at ??= now();
                $inv->reminder_at = now()->addDays(config('survey.reminder_days'));
            } elseif ($delivery->kind === 'reminder') {
                $inv->reminder_count++;
                $inv->reminder_at = now()->addDays(config('survey.reminder_days'));
            }
            $inv->save();
        });
    }
    public function failed(?\Throwable $e): void
    {
        EmailDelivery::whereKey($this->deliveryId)->where('status', '!=', 'sent')->update(['status' => 'failed', 'last_error' => 'Pengiriman gagal. Periksa koneksi SMTP dan log worker.']);
    }
}
